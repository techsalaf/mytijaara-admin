<?php

namespace Modules\WhatsAppVendorConcierge\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;

class MigrateLegacyAiProvidersTest extends TestCase
{
    use DatabaseTransactions;

    public function test_migrates_legacy_providers_into_connections_and_models(): void
    {
        $legacy = WhatsAppAiProvider::create([
            'name' => 'Legacy Groq Fast',
            'driver' => 'openai',
            'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => 'gsk-legacy-12345',
            'model' => 'llama-3.3-70b-versatile',
            'priority' => 3,
            'is_active' => true,
            'status' => 'working',
        ]);

        $this->artisan('whatsapp:migrate-legacy-ai-providers')
            ->assertExitCode(0);

        $connection = AiProviderConnection::where('name', 'Legacy Groq Fast Account')->first();
        $this->assertNotNull($connection);
        $this->assertEquals('gsk-legacy-12345', $connection->getApiKey());
        $this->assertEquals('groq', $connection->definition->slug);

        $model = AiProviderModel::where('connection_id', $connection->id)->where('model_id', 'llama-3.3-70b-versatile')->first();
        $this->assertNotNull($model);
        $this->assertTrue($model->is_enabled);
        $this->assertEquals(3, $model->priority);

        // Run again to ensure idempotency
        $connCountBefore = AiProviderConnection::count();
        $modelCountBefore = AiProviderModel::count();

        $this->artisan('whatsapp:migrate-legacy-ai-providers')
            ->assertExitCode(0);

        $this->assertEquals($connCountBefore, AiProviderConnection::count());
        $this->assertEquals($modelCountBefore, AiProviderModel::count());
    }
}
