<?php
namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\tests\ConciergeDatabaseTestTrait;
use Modules\WhatsAppVendorConcierge\database\seeders\AdditionalAiProviderSeeder;
use Modules\WhatsAppVendorConcierge\app\Models\{AiProviderConnection, AiProviderDefinition};
use Modules\WhatsAppVendorConcierge\app\Services\ModelDiscoveryService;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController;

class AdditionalAiProviderSetupTest extends TestCase
{
    use DatabaseTransactions, ConciergeDatabaseTestTrait;
    protected function setUp(): void { parent::setUp(); $this->setupConciergeTables(); }

    public function test_seed_is_additive_and_idempotent(): void
    {
        (new AdditionalAiProviderSeeder)->run();
        $definition = AiProviderDefinition::where('slug', 'cerebras')->firstOrFail();
        $definition->update(['name' => 'My trial']);
        (new AdditionalAiProviderSeeder)->run();
        $this->assertSame('My trial', $definition->fresh()->name);
        $this->assertSame(3, AiProviderDefinition::whereIn('slug', ['cerebras', 'cloudflare_workers_ai', 'openrouter_free'])->count());
    }

    public function test_cloudflare_key_rotation_preserves_account_and_requires_free_plan(): void
    {
        $definition = AiProviderDefinition::where('slug', 'cloudflare_workers_ai')->firstOrFail();
        $connection = AiProviderConnection::create(['definition_id' => $definition->id, 'name' => 'CF', 'credentials' => ['api_key' => 'old', 'account_id' => str_repeat('a', 32), 'free_plan_confirmed' => true], 'selection_mode' => 'manual']);
        $controller = app(AiProviderController::class);
        $controller->update(Request::create('/', 'POST', ['name' => 'CF', 'api_key' => 'new', 'account_id' => str_repeat('a', 32), 'free_plan_confirmed' => '1', 'selection_mode' => 'manual']), $connection);
        $this->assertSame('new', $connection->fresh()->getApiKey());
        $this->assertSame(str_repeat('a', 32), $connection->fresh()->credentials['account_id']);
        $this->assertStringNotContainsString('"api_key"', $connection->getRawOriginal('credentials'));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->update(Request::create('/', 'POST', ['name' => 'CF', 'account_id' => str_repeat('a', 32), 'selection_mode' => 'manual']), $connection);
    }

    public function test_failed_discovery_cannot_leave_stale_models_routable(): void
    {
        Http::fake(['*' => Http::response([], 401)]);
        $definition = AiProviderDefinition::where('slug', 'openrouter_free')->firstOrFail();
        $connection = AiProviderConnection::create(['definition_id' => $definition->id, 'name' => 'OR', 'credentials' => ['api_key' => 'bad'], 'status' => 'healthy', 'selection_mode' => 'all_compatible']);
        $model = $connection->models()->create(['model_id' => 'old:free', 'name' => 'Old', 'is_enabled' => true]);
        $result = app(ModelDiscoveryService::class)->syncConnectionModels($connection);
        $this->assertSame(0, $result['synced']);
        $this->assertSame('unverified', $connection->fresh()->status);
        $this->assertFalse($model->fresh()->is_enabled);
    }

    public function test_cerebras_creation_requires_manual_model_selection(): void
    {
        Http::fake(['*' => Http::response([], 401)]);
        $definition = AiProviderDefinition::where('slug', 'cerebras')->firstOrFail();
        app(AiProviderController::class)->store(Request::create('/', 'POST', ['definition_id' => $definition->id, 'name' => 'Trial', 'api_key' => 'test-key', 'selection_mode' => 'all_compatible']));
        $connection = AiProviderConnection::where('name', 'Trial')->firstOrFail();
        $this->assertSame('manual', $connection->selection_mode);
        $this->assertSame('unverified', $connection->status);
    }
    public function test_registered_probe_uses_adapter_and_marks_only_text_inference_verified(): void
    {
        Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'probe', 'model' => 'example:free',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
        ])]);
        $definition = AiProviderDefinition::where('slug', 'openrouter_free')->firstOrFail();
        $connection = AiProviderConnection::create(['definition_id' => $definition->id, 'name' => 'Probe', 'credentials' => ['api_key' => 'test'], 'is_active' => true, 'status' => 'models_discovered']);
        $connection->models()->create(['model_id' => 'example:free', 'name' => 'Example', 'is_enabled' => true, 'is_free_tier' => true, 'cost_per_million_input' => 0, 'cost_per_million_output' => 0]);
        $this->artisan('ai:test-models', ['connection_id' => $connection->id])->assertSuccessful();
        $this->assertSame('inference_verified', $connection->fresh()->status);
        Http::assertSentCount(1);
    }

}
