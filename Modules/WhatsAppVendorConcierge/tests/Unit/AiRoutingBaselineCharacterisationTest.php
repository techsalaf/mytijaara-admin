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

class AiRoutingBaselineCharacterisationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_provider_model_hides_api_key_and_casts_properly(): void
    {
        $provider = new WhatsAppAiProvider([
            'name' => 'Test Provider',
            'driver' => 'openai',
            'api_key' => 'sk-secret-key-12345',
            'model' => 'gpt-4o',
            'priority' => 1,
            'is_active' => true,
            'status' => 'working',
        ]);

        $array = $provider->toArray();
        $this->assertArrayNotHasKey('api_key', $array, 'API key must be hidden in toArray()');

        $json = json_encode($provider);
        $this->assertStringNotContainsString('sk-secret-key-12345', $json, 'API key must never leak into serialized JSON');
    }

    public function test_fallback_service_attempts_chain_in_strict_priority_order(): void
    {
        $attempts = [];

        $provider1 = Mockery::mock(WhatsAppAiProvider::class)->makePartial();
        $provider1->fill([
            'name' => 'Provider High Priority',
            'driver' => 'openai',
            'api_key' => 'key1',
            'model' => 'gpt-4o',
            'priority' => 1,
            'is_active' => true,
            'status' => 'working',
        ]);
        $provider1->shouldReceive('update')->once()->with(Mockery::on(function ($args) {
            return $args['status'] === 'failed';
        }));

        $provider2 = Mockery::mock(WhatsAppAiProvider::class)->makePartial();
        $provider2->fill([
            'name' => 'Provider Medium Priority',
            'driver' => 'gemini',
            'api_key' => 'key2',
            'model' => 'gemini-1.5-flash',
            'priority' => 2,
            'is_active' => true,
            'status' => 'working',
        ]);
        $provider2->shouldReceive('update')->once()->with(Mockery::on(function ($args) {
            return $args['status'] === 'failed';
        }));

        $provider3 = new WhatsAppAiProvider([
            'name' => 'Provider Low Priority',
            'driver' => 'groq',
            'api_key' => 'key3',
            'model' => 'llama-3.3-70b-versatile',
            'priority' => 3,
            'is_active' => true,
            'status' => 'working',
        ]);

        $service = Mockery::mock(AiFallbackService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getProviders')->andReturn(collect([$provider1, $provider2, $provider3]));

        $agent = Mockery::mock(Agent::class);
        $mockResponse = Mockery::mock(AgentResponse::class);

        $agent->shouldReceive('prompt')->andReturnUsing(function ($text, ...$kwargs) use (&$attempts, $mockResponse) {
            $provider = $kwargs['provider'] ?? ($kwargs[1] ?? null);
            $attempts[] = $provider;

            if ($provider === 'openai') {
                throw new Exception('OpenAI 429 Too Many Requests');
            }
            if ($provider === 'gemini') {
                throw new Exception('Gemini 503 Overloaded');
            }
            if ($provider === 'groq') {
                return $mockResponse;
            }
            throw new Exception("Unexpected provider {$provider}");
        });

        $result = $service->promptAgent($agent, 'Test prompt');

        $this->assertSame($mockResponse, $result['response']);
        $this->assertEquals('Provider Low Priority', $result['provider']->name);
        $this->assertEquals(['openai', 'gemini', 'groq'], $attempts, 'Providers must be attempted in exact priority sequence');
    }
}
