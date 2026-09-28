<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Services\ModelDiscoveryService;

class RefreshAiModelsCommandTest extends HardeningTestCase
{
    public function test_it_skips_a_recent_refresh_without_calling_provider_discovery(): void
    {
        $definitionId = DB::table('ai_provider_definitions')->insertGetId([
            'slug' => 'fixture-provider', 'name' => 'Fixture', 'adapter_class' => 'Fixture',
            'supports_model_discovery' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $connection = AiProviderConnection::create([
            'definition_id' => $definitionId, 'name' => 'Fixture connection', 'credentials' => '{}',
            'is_active' => true, 'verification' => ['discovery' => ['at' => now()->toIso8601String()]],
        ]);
        $service = \Mockery::mock(ModelDiscoveryService::class);
        $service->shouldNotReceive('syncConnectionModels');
        $this->app->instance(ModelDiscoveryService::class, $service);

        $this->artisan('whatsapp:refresh-ai-models', ['--connection' => $connection->id])
            ->expectsOutputToContain('SKIP #'.$connection->id)
            ->assertExitCode(0);
    }
}
