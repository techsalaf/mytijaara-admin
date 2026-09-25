<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Responses\AgentResponse;
use Modules\WhatsAppVendorConcierge\app\Exceptions\AllAiProvidersFailedException;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingPolicy;
use Modules\WhatsAppVendorConcierge\app\Services\AiRouterService;
use Modules\WhatsAppVendorConcierge\app\Services\AiCircuitBreakerService;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenAiCompatibleAdapter;
use Modules\WhatsAppVendorConcierge\tests\ConciergeDatabaseTestTrait;
use Mockery;
use Exception;

class AiRouterServiceTest extends TestCase
{
    use DatabaseTransactions;
    use ConciergeDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupConciergeTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_healthy_and_expired_cooldown_connections_reenter_routing(): void
    {
        $definition = AiProviderDefinition::create(['slug'=>'recovery-test','name'=>'Recovery test','adapter_class'=>OpenAiCompatibleAdapter::class]);
        $connection = AiProviderConnection::create(['definition_id'=>$definition->id,'name'=>'Verified account','credentials'=>['api_key'=>'test-key'],'is_active'=>true,'status'=>'healthy']);
        $model = AiProviderModel::create(['connection_id'=>$connection->id,'model_id'=>'test-model','name'=>'Test model','is_enabled'=>true,'supports_tool_calling'=>true]);
        $router = app(AiRouterService::class);
        $this->assertTrue($router->resolveEligibleModels(true)->contains('id',$model->id));
        $connection->update(['status'=>'cooldown','cooldown_until'=>now()->addMinutes(5)]);
        $this->assertFalse($router->resolveEligibleModels(true)->contains('id',$model->id));
        $connection->update(['cooldown_until'=>now()->subMinute()]);
        $this->assertTrue($router->resolveEligibleModels(true)->contains('id',$model->id));
        $this->assertSame('healthy',$connection->fresh()->status);
        $connection->update(['status'=>'auth_failed']);
        $this->assertFalse($router->resolveEligibleModels(true)->contains('id',$model->id));
    }

    public function test_orders_free_models_first_in_free_first_strategy(): void
    {
        $circuitBreaker = Mockery::mock(AiCircuitBreakerService::class);
        $circuitBreaker->shouldReceive('isConnectionAvailable')->andReturn(true);

        $router = new AiRouterService($circuitBreaker);

        $paidModel = new AiProviderModel([
            'model_id' => 'gpt-4o',
            'is_free_tier' => false,
            'priority' => 1,
            'weight' => 1,
        ]);

        $freeModel = new AiProviderModel([
            'model_id' => 'llama-3.3-70b-versatile',
            'is_free_tier' => true,
            'priority' => 5,
            'weight' => 1,
        ]);

        $ordered = $router->orderCandidatesByStrategy(collect([$paidModel, $freeModel]), 'free_first');

        $this->assertEquals('llama-3.3-70b-versatile', $ordered->first()->model_id, 'Free model must be prioritized first in free_first strategy');
        $this->assertEquals('gpt-4o', $ordered->last()->model_id);
    }

    public function test_filters_models_without_tool_calling_when_agent_requires_tools(): void
    {
        $definition = AiProviderDefinition::create([
            'slug' => 'test-router-tools',
            'name' => 'Router Tool Test',
            'adapter_class' => OpenAiCompatibleAdapter::class,
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Tools Connection',
            'credentials' => ['api_key' => 'test-key'],
            'is_active' => true, 'status' => 'inference_verified',
           
        ]);

        $noToolModel = AiProviderModel::create([
            'connection_id' => $connection->id,
            'model_id' => 'model-no-tools',
            'name' => 'No Tools Model',
            'is_enabled' => true,
            'supports_tool_calling' => false,
        ]);

        $toolModel = AiProviderModel::create([
            'connection_id' => $connection->id,
            'model_id' => 'model-with-tools',
            'name' => 'Tool Capable Model',
            'is_enabled' => true,
            'supports_tool_calling' => true,
        ]);

        $circuitBreaker = app(AiCircuitBreakerService::class);
        $router = new AiRouterService($circuitBreaker);

        $eligible = $router->resolveEligibleModels(requiresTools: true);

        $this->assertTrue($eligible->contains('model_id', 'model-with-tools'));
        $this->assertFalse($eligible->contains('model_id', 'model-no-tools'), 'Models lacking tool calling must be excluded');
    }

    public function test_fails_over_to_next_candidate_if_first_fails(): void
    {
        $definition = AiProviderDefinition::create([
            'slug' => 'test-failover-def',
            'name' => 'Failover Test',
            'adapter_class' => OpenAiCompatibleAdapter::class,
        ]);

        $connection1 = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Primary Connection (Failing)',
            'credentials' => ['api_key' => 'fail-key'],
            'is_active' => true, 'status' => 'inference_verified',
           
        ]);

        $model1 = AiProviderModel::create([
            'connection_id' => $connection1->id,
            'model_id' => 'failing-model',
            'name' => 'Failing Model',
            'is_enabled' => true,
            'is_free_tier' => true,
            'priority' => 1,
        ]);

        $connection2 = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Backup Connection (Working)',
            'credentials' => ['api_key' => 'success-key'],
            'is_active' => true, 'status' => 'inference_verified',
           
        ]);

        $model2 = AiProviderModel::create([
            'connection_id' => $connection2->id,
            'model_id' => 'working-model',
            'name' => 'Working Model',
            'is_enabled' => true,
            'is_free_tier' => true,
            'priority' => 2,
        ]);

        $adapterMock = Mockery::mock(OpenAiCompatibleAdapter::class);
        $mockResponse = Mockery::mock(AgentResponse::class);

        // First model fails, second succeeds
        $adapterMock->shouldReceive('invokeAgent')
            ->with(Mockery::on(fn($c) => $c->id === $connection1->id), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->once()
            ->andThrow(new Exception('503 Service Unavailable'));

        $adapterMock->shouldReceive('invokeAgent')
            ->with(Mockery::on(fn($c) => $c->id === $connection2->id), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->once()
            ->andReturn([
                'response' => $mockResponse,
                'latency_ms' => 250,
                'prompt_tokens' => 50,
                'completion_tokens' => 25,
                'cost_usd' => 0.0,
            ]);

        $adapterMock->shouldReceive('normaliseError')->andReturn([
            'type' => 'overloaded',
            'message' => '503 Service Unavailable',
            'retry_after_seconds' => 30,
        ]);

        $this->app->instance(OpenAiCompatibleAdapter::class, $adapterMock);

        $circuitBreaker = app(AiCircuitBreakerService::class);
        $router = new AiRouterService($circuitBreaker);

        $agent = Mockery::mock(Agent::class);
        $result = $router->routeAndPrompt($agent, 'Hello concierge');

        $this->assertSame($mockResponse, $result['response']);
        $this->assertEquals('working-model', $result['model']->model_id);
        $this->assertEquals('Backup Connection (Working)', $result['connection']->name);
    }

    public function test_throws_all_providers_failed_when_all_fail(): void
    {
        $definition = AiProviderDefinition::create([
            'slug' => 'test-all-fail-def',
            'name' => 'All Fail Test',
            'adapter_class' => OpenAiCompatibleAdapter::class,
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Only Connection',
            'credentials' => ['api_key' => 'fail-key'],
            'is_active' => true, 'status' => 'inference_verified',
           
        ]);

        $model = AiProviderModel::create([
            'connection_id' => $connection->id,
            'model_id' => 'broken-model',
            'name' => 'Broken Model',
            'is_enabled' => true,
            'priority' => 1,
        ]);

        $adapterMock = Mockery::mock(OpenAiCompatibleAdapter::class);
        $adapterMock->shouldReceive('invokeAgent')->once()->andThrow(new Exception('Network Error'));
        $adapterMock->shouldReceive('normaliseError')->andReturn([
            'type' => 'timeout',
            'message' => 'Network Error',
            'retry_after_seconds' => 15,
        ]);

        $this->app->instance(OpenAiCompatibleAdapter::class, $adapterMock);

        $circuitBreaker = app(AiCircuitBreakerService::class);
        $router = new AiRouterService($circuitBreaker);

        $agent = Mockery::mock(Agent::class);

        $this->expectException(AllAiProvidersFailedException::class);
        $router->routeAndPrompt($agent, 'Hello concierge');
    }
}
