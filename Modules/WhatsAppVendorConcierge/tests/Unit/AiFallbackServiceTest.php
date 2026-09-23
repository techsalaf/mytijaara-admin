<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;
use Modules\WhatsAppVendorConcierge\app\Services\AiFallbackService;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Exception;
use Mockery;
use Illuminate\Support\Collection;

class AiFallbackServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_throws_exception_if_no_providers_available()
    {
        $service = Mockery::mock(AiFallbackService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getProviders')->andReturn(collect([]));

        $agent = Mockery::mock(Agent::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No active AI providers configured in the database.');

        $service->promptAgent($agent, 'Hello');
    }

    public function test_it_uses_first_working_provider()
    {
        $provider = new WhatsAppAiProvider([
            'name' => 'Provider 1',
            'driver' => 'openai',
            'api_key' => 'key1',
            'model' => 'gpt-4o',
            'priority' => 1,
            'is_active' => true,
            'status' => 'working',
        ]);

        $service = Mockery::mock(AiFallbackService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getProviders')->andReturn(collect([$provider]));

        $agent = Mockery::mock(Agent::class);
        $mockResponse = Mockery::mock(AgentResponse::class);

        $agent->shouldReceive('prompt')->once()->andReturn($mockResponse);

        $result = $service->promptAgent($agent, 'Hello');

        $this->assertEquals($mockResponse, $result['response']);
        $this->assertEquals('Provider 1', $result['provider']->name);
    }

    public function test_it_falls_back_if_first_provider_fails()
    {
        $provider1 = Mockery::mock(WhatsAppAiProvider::class)->makePartial();
        $provider1->fill([
            'name' => 'Provider 1',
            'driver' => 'openai',
            'api_key' => 'key1',
            'model' => 'gpt-4o',
            'priority' => 1,
            'is_active' => true,
            'status' => 'working',
        ]);
        $provider1->shouldReceive('update')->once()->with(Mockery::on(function ($args) {
            return $args['status'] === 'failed' && isset($args['last_failed_at']);
        }));

        $provider2 = new WhatsAppAiProvider([
            'name' => 'Provider 2',
            'driver' => 'anthropic',
            'api_key' => 'key2',
            'model' => 'claude-3',
            'priority' => 2,
            'is_active' => true,
            'status' => 'working',
        ]);

        $service = Mockery::mock(AiFallbackService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getProviders')->andReturn(collect([$provider1, $provider2]));

        $agent = Mockery::mock(Agent::class);
        $mockResponse = Mockery::mock(AgentResponse::class);

        $agent->shouldReceive('prompt')->andReturnUsing(function ($text, ...$kwargs) use ($mockResponse) {
            $provider = $kwargs['provider'] ?? (isset($kwargs[1]) ? $kwargs[1] : null);
            
            if ($provider === 'openai') {
                throw new Exception('OpenAI API down');
            }
            
            if ($provider === 'anthropic') {
                return $mockResponse;
            }
        });

        $result = $service->promptAgent($agent, 'Hello');

        $this->assertEquals($mockResponse, $result['response']);
        $this->assertEquals('Provider 2', $result['provider']->name);
    }
}
