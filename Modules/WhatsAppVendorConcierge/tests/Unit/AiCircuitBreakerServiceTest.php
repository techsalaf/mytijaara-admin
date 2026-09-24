<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingAttempt;
use Modules\WhatsAppVendorConcierge\app\Models\AiUsageRecord;
use Modules\WhatsAppVendorConcierge\app\Services\AiCircuitBreakerService;
use Exception;

use Modules\WhatsAppVendorConcierge\tests\ConciergeDatabaseTestTrait;

class AiCircuitBreakerServiceTest extends TestCase
{
    use DatabaseTransactions;
    use ConciergeDatabaseTestTrait;

    protected AiProviderDefinition $definition;
    protected AiProviderConnection $connection;
    protected AiProviderModel $model;
    protected AiCircuitBreakerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupConciergeTables();

        $this->definition = AiProviderDefinition::create([
            'slug' => 'test-circuit-cb',
            'name' => 'Circuit Breaker Test',
            'adapter_class' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenAiCompatibleAdapter::class,
        ]);

        $this->connection = AiProviderConnection::create([
            'definition_id' => $this->definition->id,
            'name' => 'CB Connection',
            'credentials' => ['api_key' => 'cb-test-key'],
            'status' => 'healthy',
            'is_active' => true,
        ]);

        $this->model = AiProviderModel::create([
            'connection_id' => $this->connection->id,
            'model_id' => 'cb-model-1',
            'name' => 'CB Model',
            'is_enabled' => true,
        ]);

        $this->service = new AiCircuitBreakerService;
    }

    public function test_record_success_resets_failures_and_updates_usage(): void
    {
        $this->connection->update(['consecutive_failures' => 2, 'status' => 'degraded']);

        $this->service->recordSuccess(
            connection: $this->connection,
            model: $this->model,
            latencyMs: 350,
            promptTokens: 100,
            completionTokens: 50,
            costUsd: 0.0015,
            conversationId: 42
        );

        $this->connection->refresh();
        $this->assertEquals(0, $this->connection->consecutive_failures);
        $this->assertEquals('healthy', $this->connection->status);
        $this->assertEquals(0.0015, (float) $this->connection->current_day_cost_usd);

        // Check attempt record
        $this->assertDatabaseHas('ai_routing_attempts', [
            'connection_id' => $this->connection->id,
            'status' => 'success',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
        ]);

        // Check aggregate record
        $this->assertDatabaseHas('ai_usage_records', [
            'connection_id' => $this->connection->id,
            'successful_requests' => 1,
            'total_prompt_tokens' => 100,
            'total_completion_tokens' => 50,
        ]);
    }

    public function test_rate_limit_transitions_status_and_auto_recovers(): void
    {
        $e = new Exception('Rate limit reached. Try again in 30s.', 429);
        $this->service->recordFailure($this->connection, $this->model, $e);

        $this->connection->refresh();
        $this->assertEquals('rate_limited', $this->connection->status);
        $this->assertNotNull($this->connection->rate_limit_reset_at);
        $this->assertFalse($this->service->isConnectionAvailable($this->connection));

        // Simulate time pass
        $this->connection->update(['rate_limit_reset_at' => now()->subSecond()]);
        $this->assertTrue($this->service->isConnectionAvailable($this->connection));
        $this->connection->refresh();
        $this->assertEquals('healthy', $this->connection->status);
    }

    public function test_consecutive_errors_trigger_cooldown(): void
    {
        $e = new Exception('Service Unavailable', 503);

        // Failure 1 -> degraded
        $this->service->recordFailure($this->connection, $this->model, $e);
        $this->connection->refresh();
        $this->assertEquals('degraded', $this->connection->status);

        // Failure 2 -> degraded
        $this->service->recordFailure($this->connection, $this->model, $e);
        $this->connection->refresh();
        $this->assertEquals('degraded', $this->connection->status);

        // Failure 3 -> triggers cooldown
        $this->service->recordFailure($this->connection, $this->model, $e);
        $this->connection->refresh();
        $this->assertEquals('cooldown', $this->connection->status);
        $this->assertNotNull($this->connection->cooldown_until);
        $this->assertFalse($this->service->isConnectionAvailable($this->connection));
    }

    public function test_budget_exhaustion_transitions_to_budget_exhausted(): void
    {
        $this->connection->update([
            'daily_budget_usd' => 0.05,
            'current_day_cost_usd' => 0.04,
            'cost_reset_day' => now()->toDateString(),
            'cost_reset_month' => now()->format('Y-m'),
        ]);

        $this->service->recordSuccess(
            connection: $this->connection,
            model: $this->model,
            latencyMs: 200,
            promptTokens: 2000,
            completionTokens: 1000,
            costUsd: 0.02 // Exceeds 0.05 total
        );

        $this->connection->refresh();
        $this->assertEquals('budget_exhausted', $this->connection->status);
        $this->assertFalse($this->service->isConnectionAvailable($this->connection));
    }
}
