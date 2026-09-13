<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

abstract class HardeningTestCase extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array',
            'app.url' => 'https://dashboard.mytijaara.test',
            'whatsapp-vendor-concierge.queue.connection' => 'database',
            'whatsapp-vendor-concierge.api.phone_number_id' => 'test-phone',
            'whatsapp-vendor-concierge.api.access_token' => 'test-token',
            'whatsapp-vendor-concierge.api.app_secret' => 'test-secret',
            'whatsapp-vendor-concierge.webhook.verify_token' => 'test-verify',
        ]);
        DB::purge('sqlite');
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        \Illuminate\Support\Facades\URL::forceRootUrl('https://dashboard.mytijaara.test');
        // These tests exercise the real module migrations and relationships. Core
        // tables are minimal FK targets, not a substitute for core integration tests.
        foreach (['vendors', 'stores', 'users'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('key'); $table->text('value')->nullable(); $table->timestamps();
        });
        foreach (glob(base_path('Modules/WhatsAppVendorConcierge/database/migrations/*.php')) as $migration) {
            (require $migration)->up();
        }
    }

    protected function application(): array
    {
        $contact = WhatsAppContact::create(['whatsapp_id' => '2348000000001', 'phone_number' => '2348000000001']);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id, 'status' => 'started', 'current_step' => 'account_password',
            'collected_data' => ['business_name' => 'Ibadan Store'], 'expires_at' => now()->addDay(),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id, 'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active', 'current_step' => 'account_password',
        ]);
        return [$contact, $session, $conversation];
    }
}
