<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Laravel\Ai\Responses\AgentResponse;
use Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin\AiRoutingDashboardController;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingPolicy;
use Modules\WhatsAppVendorConcierge\app\Services\AiRouterService;
use Mockery;

class AiRoutingDashboardControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_loads_dashboard_data(): void
    {
        $router = Mockery::mock(AiRouterService::class);
        $controller = new AiRoutingDashboardController($router);

        $view = $controller->index();

        $this->assertEquals('whatsapp-vendor-concierge::admin.ai_routing.index', $view->name());
        $data = $view->getData();
        $this->assertArrayHasKey('policies', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('recentAttempts', $data);
        $this->assertArrayHasKey('availableModels', $data);
    }

    public function test_update_policy_updates_strategy(): void
    {
        $policy = AiRoutingPolicy::create([
            'name' => 'Custom Strategy Policy',
            'slug' => 'custom_strategy',
            'strategy' => 'free_first',
            'requires_tool_calling' => false,
            'is_active' => true,
        ]);

        $router = Mockery::mock(AiRouterService::class);
        $controller = new AiRoutingDashboardController($router);

        $request = Request::create("/admin/whatsapp/ai-routing/policy/{$policy->id}", 'PUT', [
            'strategy' => 'round_robin',
            'requires_tool_calling' => '1',
            'max_latency_ms' => '5000',
        ]);

        $response = $controller->updatePolicy($request, $policy);
        $this->assertTrue($response->isRedirect());

        $fresh = $policy->fresh();
        $this->assertEquals('round_robin', $fresh->strategy);
        $this->assertTrue($fresh->requires_tool_calling);
        $this->assertEquals(5000, $fresh->max_latency_ms);
    }

    public function test_simulate_endpoint_returns_json_result(): void
    {
        $router = Mockery::mock(AiRouterService::class);

        $definition = new AiProviderDefinition(['name' => 'Groq Cloud', 'slug' => 'groq']);
        $connection = new AiProviderConnection(['name' => 'Groq Production Key']);
        $connection->setRelation('definition', $definition);

        $model = new AiProviderModel([
            'model_id' => 'llama-3.3-70b-versatile',
            'name' => 'Llama 3.3 70B',
            'is_free_tier' => true,
        ]);
        $model->setRelation('connection', $connection);

        $mockResponse = Mockery::mock(AgentResponse::class);
        $mockResponse->text = 'Hello! I am your MyTijaara assistant.';

        $router->shouldReceive('routeAndPrompt')
            ->once()
            ->andReturn([
                'response' => $mockResponse,
                'model' => $model,
                'connection' => $connection,
                'latency_ms' => 180,
                'prompt_tokens' => 25,
                'completion_tokens' => 15,
                'cost_usd' => 0.0,
            ]);

        $controller = new AiRoutingDashboardController($router);

        $request = Request::create('/admin/whatsapp/ai-routing/simulate', 'POST', [
            'prompt' => 'Hello',
        ]);

        $response = $controller->simulate($request);
        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertEquals('llama-3.3-70b-versatile', $data['model']);
        $this->assertTrue($data['is_free']);
        $this->assertEquals('Hello! I am your MyTijaara assistant.', $data['response_text']);
    }
}
