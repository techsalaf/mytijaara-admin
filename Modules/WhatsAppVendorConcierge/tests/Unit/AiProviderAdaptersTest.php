<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenAiCompatibleAdapter;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\GeminiAdapter;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\GroqAdapter;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenRouterAdapter;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\NvidiaNimAdapter;
use Exception;

class AiProviderAdaptersTest extends TestCase
{
    public function test_adapter_factory_resolves_correct_adapters(): void
    {
        $openaiDef = new AiProviderDefinition(['slug' => 'openai', 'adapter_class' => OpenAiCompatibleAdapter::class]);
        $geminiDef = new AiProviderDefinition(['slug' => 'gemini', 'adapter_class' => GeminiAdapter::class]);
        $groqDef = new AiProviderDefinition(['slug' => 'groq', 'adapter_class' => GroqAdapter::class]);
        $openrouterDef = new AiProviderDefinition(['slug' => 'openrouter', 'adapter_class' => OpenRouterAdapter::class]);
        $nvidiaDef = new AiProviderDefinition(['slug' => 'nvidia_nim', 'adapter_class' => NvidiaNimAdapter::class]);

        $this->assertInstanceOf(OpenAiCompatibleAdapter::class, AdapterFactory::forDefinition($openaiDef));
        $this->assertInstanceOf(GeminiAdapter::class, AdapterFactory::forDefinition($geminiDef));
        $this->assertInstanceOf(GroqAdapter::class, AdapterFactory::forDefinition($groqDef));
        $this->assertInstanceOf(OpenRouterAdapter::class, AdapterFactory::forDefinition($openrouterDef));
        $this->assertInstanceOf(NvidiaNimAdapter::class, AdapterFactory::forDefinition($nvidiaDef));
    }

    public function test_error_normalisation_and_cost_calculation(): void
    {
        $adapter = new OpenAiCompatibleAdapter;

        // Rate limit with retry after
        $rateLimitErr = new Exception('Rate limit reached. Try again in 45s.', 429);
        $normRate = $adapter->normaliseError($rateLimitErr);
        $this->assertEquals('rate_limit', $normRate['type']);
        $this->assertEquals(45, $normRate['retry_after_seconds']);

        // Auth failed
        $authErr = new Exception('Invalid API key provided.', 401);
        $normAuth = $adapter->normaliseError($authErr);
        $this->assertEquals('auth_failed', $normAuth['type']);

        // Timeout
        $timeoutErr = new Exception('Connection timed out.', 504);
        $normTimeout = $adapter->normaliseError($timeoutErr);
        $this->assertEquals('timeout', $normTimeout['type']);

        // Cost calculation - free tier
        $freeModel = new AiProviderModel([
            'is_free_tier' => true,
            'cost_per_million_input' => 10.0,
            'cost_per_million_output' => 30.0,
        ]);
        $this->assertEquals(0.0, $adapter->calculateCost($freeModel, 1000, 500));

        // Cost calculation - paid model
        $paidModel = new AiProviderModel([
            'is_free_tier' => false,
            'cost_per_million_input' => 5.0, // $5 / 1M input tokens
            'cost_per_million_output' => 15.0, // $15 / 1M output tokens
        ]);
        // 10,000 prompt tokens = $0.05, 5,000 completion tokens = $0.075 -> Total = $0.125
        $this->assertEquals(0.125, $adapter->calculateCost($paidModel, 10000, 5000));
    }

    public function test_openai_adapter_connection_and_discovery_with_fakes(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4o', 'object' => 'model'],
                    ['id' => 'text-embedding-3-small', 'object' => 'model'],
                    ['id' => 'whisper-1', 'object' => 'model'],
                ],
            ], 200),
        ]);

        $connection = new AiProviderConnection([
            'credentials' => ['api_key' => 'sk-mock-key'],
            'base_url_override' => 'https://api.openai.com/v1',
        ]);

        $adapter = new OpenAiCompatibleAdapter;
        $testResult = $adapter->testConnection($connection);
        $this->assertTrue($testResult['success']);
        $this->assertStringContainsString('3 models', $testResult['message']);

        $models = $adapter->discoverModels($connection);
        // text-embedding and whisper should be filtered out
        $this->assertCount(1, $models);
        $this->assertEquals('gpt-4o', $models[0]['id']);
        $this->assertTrue($models[0]['supports_tool_calling']);
    }

    public function test_groq_adapter_discovery_with_fakes(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'llama-3.3-70b-versatile', 'context_window' => 128000],
                    ['id' => 'whisper-large-v3', 'context_window' => null],
                ],
            ], 200),
        ]);

        $connection = new AiProviderConnection([
            'credentials' => ['api_key' => 'gsk-mock'],
            'base_url_override' => 'https://api.groq.com/openai/v1',
        ]);

        $adapter = new GroqAdapter;
        $models = $adapter->discoverModels($connection);

        $this->assertCount(1, $models);
        $this->assertEquals('llama-3.3-70b-versatile', $models[0]['id']);
        $this->assertTrue($models[0]['is_free_tier']);
        $this->assertTrue($models[0]['supports_tool_calling']);
    }

    public function test_openrouter_adapter_detects_free_models(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'meta-llama/llama-3.3-70b-instruct:free',
                        'name' => 'Llama 3.3 70B (Free)',
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                        'supported_parameters' => ['tools'],
                    ],
                    [
                        'id' => 'anthropic/claude-3.5-sonnet',
                        'name' => 'Claude 3.5 Sonnet',
                        'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                        'supported_parameters' => ['tools'],
                    ],
                ],
            ], 200),
        ]);

        $connection = new AiProviderConnection([
            'credentials' => ['api_key' => 'or-mock'],
            'base_url_override' => 'https://openrouter.ai/api/v1',
        ]);

        $adapter = new OpenRouterAdapter;
        $models = $adapter->discoverModels($connection);

        $this->assertCount(2, $models);
        $this->assertTrue($models[0]['is_free_tier']);
        $this->assertFalse($models[1]['is_free_tier']);
    }
}
