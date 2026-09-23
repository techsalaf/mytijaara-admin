<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingPolicy;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingTarget;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingAttempt;
use Modules\WhatsAppVendorConcierge\app\Models\AiUsageRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AiRoutingSchemaAndModelsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_can_create_definition_and_connection_with_encrypted_credentials(): void
    {
        $definition = AiProviderDefinition::create([
            'slug' => 'test-openai',
            'name' => 'OpenAI Test',
            'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\OpenAiCompatibleAdapter',
            'default_base_url' => 'https://api.openai.com/v1',
            'auth_type' => 'api_key',
            'supports_model_discovery' => true,
            'supports_tool_calling' => true,
            'catalogue_models' => [
                ['id' => 'gpt-4o', 'name' => 'GPT-4o'],
            ],
        ]);

        $this->assertDatabaseHas('ai_provider_definitions', ['slug' => 'test-openai']);
        $this->assertTrue($definition->supports_model_discovery);
        $this->assertIsArray($definition->catalogue_models);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'My Account 1',
            'credentials' => [
                'api_key' => 'sk-test-super-secret-12345',
            ],
            'status' => 'healthy',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('ai_provider_connections', ['name' => 'My Account 1']);

        // Check encryption and masking
        $freshConnection = AiProviderConnection::find($connection->id);
        $this->assertEquals('sk-test-super-secret-12345', $freshConnection->getApiKey());
        $this->assertArrayNotHasKey('credentials', $freshConnection->toArray());
        $this->assertStringNotContainsString('sk-test-super-secret-12345', json_encode($freshConnection));

        // Check availability
        $this->assertTrue($freshConnection->isAvailable());

        // Test budget limit exhaustion
        $freshConnection->update([
            'daily_budget_usd' => 1.0000,
            'current_day_cost_usd' => 1.5000,
        ]);
        $this->assertFalse($freshConnection->isAvailable());
    }

    public function test_can_create_models_and_link_to_connection(): void
    {
        $definition = AiProviderDefinition::create([
            'slug' => 'test-groq',
            'name' => 'Groq Test',
            'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\GroqAdapter',
            'default_base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $definition->id,
            'name' => 'Groq Primary',
            'credentials' => ['api_key' => 'gsk_test123'],
            'is_active' => true,
        ]);

        $model1 = AiProviderModel::create([
            'connection_id' => $connection->id,
            'model_id' => 'llama-3.3-70b-versatile',
            'name' => 'Llama 3.3 70B',
            'is_enabled' => true,
            'supports_tool_calling' => true,
            'is_free_tier' => true,
            'priority' => 1,
        ]);

        $model2 = AiProviderModel::create([
            'connection_id' => $connection->id,
            'model_id' => 'mixtral-8x7b-32768',
            'name' => 'Mixtral 8x7B',
            'is_enabled' => true,
            'supports_tool_calling' => false,
            'is_free_tier' => true,
            'priority' => 2,
        ]);

        $this->assertCount(2, $connection->models);
        $this->assertCount(1, $connection->models()->toolCapable()->get());
        $this->assertCount(2, $connection->models()->freeTier()->get());
        $this->assertEquals('llama-3.3-70b-versatile', $connection->enabledModels()->first()->model_id);
    }

    public function test_routing_policy_and_targets(): void
    {
        $policy = AiRoutingPolicy::create([
            'name' => 'Concierge Free First',
            'slug' => 'concierge_free_first',
            'strategy' => 'free_first',
            'requires_tool_calling' => true,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('ai_routing_policies', ['slug' => 'concierge_free_first']);
        $this->assertTrue($policy->requires_tool_calling);
    }
}
