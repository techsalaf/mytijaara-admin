<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\ModelDiscoveryService;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenAiCompatibleAdapter;

class ModelDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sync_connection_models_uses_api_discovery_when_supported(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4o', 'object' => 'model'],
                    ['id' => 'gpt-4o-mini', 'object' => 'model'],
                ],
            ], 200),
        ]);

        $definition = AiProviderDefinition::create([
            'slug' => 'test-disc-openai',
            'name' => 'OpenAI Discovery Test',
            'adapter_class' => OpenAiCompatibleAdapter::class,
            'default_base_url' => 'https://api.openai.com/v1',
            'supports_model_discovery' => true,
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Test Acc',
            'credentials' => ['api_key' => 'sk-test-key'],
            'selection_mode' => 'all_compatible',
        ]);

        $service = new ModelDiscoveryService;
        $result = $service->syncConnectionModels($connection);

        $this->assertEquals(2, $result['synced']);
        $this->assertEquals(2, $result['new']);

        $models = AiProviderModel::where('connection_id', $connection->id)->get();
        $this->assertCount(2, $models);
        $this->assertTrue($models->firstWhere('model_id', 'gpt-4o')->is_enabled);
        $this->assertTrue($models->firstWhere('model_id', 'gpt-4o')->supports_tool_calling);
    }

    public function test_sync_connection_models_in_auto_include_free_mode(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'meta-llama/llama-3.3-70b-instruct:free',
                        'name' => 'Llama 3.3 Free',
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                    ],
                    [
                        'id' => 'openai/gpt-4o',
                        'name' => 'GPT-4o Paid',
                        'pricing' => ['prompt' => '0.000005', 'completion' => '0.000015'],
                    ],
                ],
            ], 200),
        ]);

        $definition = AiProviderDefinition::create([
            'slug' => 'test-openrouter',
            'name' => 'OpenRouter Test',
            'adapter_class' => \Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\OpenRouterAdapter::class,
            'default_base_url' => 'https://openrouter.ai/api/v1',
            'supports_model_discovery' => true,
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'OpenRouter Free Key',
            'credentials' => ['api_key' => 'or-free-test'],
            'selection_mode' => 'auto_include_free',
        ]);

        $service = new ModelDiscoveryService;
        $service->syncConnectionModels($connection);

        $freeModel = AiProviderModel::where('connection_id', $connection->id)
            ->where('model_id', 'meta-llama/llama-3.3-70b-instruct:free')
            ->first();

        $paidModel = AiProviderModel::where('connection_id', $connection->id)
            ->where('model_id', 'openai/gpt-4o')
            ->first();

        $this->assertNotNull($freeModel);
        $this->assertTrue($freeModel->is_enabled);
        $this->assertEquals(1, $freeModel->priority, 'Free models get priority 1');

        $this->assertNotNull($paidModel);
        $this->assertFalse($paidModel->is_enabled, 'Paid models are disabled in auto_include_free mode');
    }

    public function test_sync_falls_back_to_catalogue_when_api_fails(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['error' => 'API Unavailable'], 503),
        ]);

        $definition = AiProviderDefinition::create([
            'slug' => 'test-catalogue-fallback',
            'name' => 'Fallback Test',
            'adapter_class' => OpenAiCompatibleAdapter::class,
            'default_base_url' => 'https://api.openai.com/v1',
            'supports_model_discovery' => true,
            'catalogue_models' => [
                ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'tools' => true, 'free' => false],
                ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'tools' => true, 'free' => false],
            ],
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Fallback Acc',
            'credentials' => ['api_key' => 'sk-invalid'],
            'selection_mode' => 'all_compatible',
        ]);

        $service = new ModelDiscoveryService;
        $result = $service->syncConnectionModels($connection);

        $this->assertEquals(2, $result['synced']);
        $models = AiProviderModel::where('connection_id', $connection->id)->get();
        $this->assertCount(2, $models);
    }
}
