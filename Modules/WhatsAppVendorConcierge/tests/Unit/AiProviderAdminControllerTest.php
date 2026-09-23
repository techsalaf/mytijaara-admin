<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiProviderController;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\ModelDiscoveryService;

class AiProviderAdminControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected AiProviderDefinition $definition;
    protected AiProviderConnection $connection;
    protected AiProviderController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->definition = AiProviderDefinition::create([
            'slug' => 'test-admin-openai',
            'name' => 'OpenAI Admin Test',
            'adapter_class' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenAiCompatibleAdapter::class,
            'default_base_url' => 'https://api.openai.com/v1',
            'supports_model_discovery' => true,
        ]);

        $this->connection = AiProviderConnection::create([
            'definition_id' => $this->definition->id,
            'name' => 'Admin Controller Test Acc',
            'credentials' => ['api_key' => 'sk-admin-test-12345'],
            'selection_mode' => 'all_compatible',
            'status' => 'healthy',
            'is_active' => true,
        ]);

        $this->controller = new AiProviderController(app(ModelDiscoveryService::class));
    }

    public function test_index_returns_view_with_connections_and_definitions(): void
    {
        $view = $this->controller->index();
        $this->assertEquals('whatsapp-vendor-concierge::admin.ai_providers.index', $view->name());
        $this->assertTrue($view->getData()['connections']->contains('id', $this->connection->id));
        $this->assertTrue($view->getData()['definitions']->contains('id', $this->definition->id));
    }

    public function test_store_creates_connection_and_encrypts_credentials(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4o', 'object' => 'model'],
                ],
            ], 200),
        ]);

        $request = Request::create('/admin/whatsapp/ai-providers/store', 'POST', [
            'definition_id' => $this->definition->id,
            'name' => 'Newly Created Groq Key',
            'api_key' => 'gsk-super-secret-key',
            'selection_mode' => 'all_compatible',
            'daily_budget_usd' => '10.00',
            'monthly_budget_usd' => '100.00',
        ]);

        $response = $this->controller->store($request);
        $this->assertTrue($response->isRedirect());

        $newConn = AiProviderConnection::where('name', 'Newly Created Groq Key')->first();
        $this->assertNotNull($newConn);
        $this->assertEquals('gsk-super-secret-key', $newConn->getApiKey());
        $this->assertDatabaseMissing('ai_provider_connections', ['credentials' => 'gsk-super-secret-key']);
    }

    public function test_test_connection_endpoint(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $response = $this->controller->test($this->connection);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('latency_ms', $data);
    }

    public function test_toggle_model_endpoint(): void
    {
        $model = AiProviderModel::create([
            'connection_id' => $this->connection->id,
            'model_id' => 'toggle-test-model',
            'name' => 'Toggle Model',
            'is_enabled' => true,
        ]);

        $resp1 = $this->controller->toggleModel($model);
        $this->assertFalse($resp1->getData(true)['is_enabled']);
        $this->assertFalse($model->fresh()->is_enabled);

        $resp2 = $this->controller->toggleModel($model);
        $this->assertTrue($resp2->getData(true)['is_enabled']);
        $this->assertTrue($model->fresh()->is_enabled);
    }
}
