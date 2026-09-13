<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\CredentialToken;
use Modules\WhatsAppVendorConcierge\app\Models\NotificationDelivery;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Tests\TestCase;

class MaintenanceCommandsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_preflight_command_passes(): void
    {
        $exitCode = Artisan::call('whatsapp:preflight');
        $this->assertEquals(0, $exitCode);
    }

    public function test_process_stuck_sessions_dry_run_and_execution(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348099887766',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'ai_active',
        ]);

        $vendor = new Vendor();
        $vendor->f_name = 'Stuck';
        $vendor->l_name = 'Session';
        $vendor->phone = '2348099887766';
        $vendor->email = 'stuck@mytijaara.test';
        $vendor->password = bcrypt('secret');
        $vendor->status = 1;
        $vendor->save();

        $store = new \App\Models\Store();
        $store->name = 'Stuck Store';
        $store->phone = '2348099887766';
        $store->email = 'stuckstore@mytijaara.test';
        $store->vendor_id = $vendor->id;
        $store->zone_id = 1;
        $store->module_id = 1;
        $store->status = 1;
        $store->save();

        // 1. Stale onboarding session
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'business_name',
            'expires_at' => Carbon::now()->subMinutes(10),
        ]);

        // 2. Abandoned notification lease
        $delivery = NotificationDelivery::create([
            'store_id' => $store->id,
            'vendor_id' => $vendor->id,
            'event_type' => 'status_update',
            'recipient_phone' => '2348099887766',
            'channel' => 'whatsapp',
            'status' => 'processing',
            'claimed_at' => Carbon::now()->subMinutes(20),
            'idempotency_key' => 'idem_stuck_' . uniqid(),
            'lease_expires_at' => Carbon::now()->subMinutes(15),
        ]);

        // 3. Stale credential token
        $token = CredentialToken::create([
            'onboarding_session_id' => $session->id,
            'token_hash' => hash('sha256', uniqid()),
            'purpose' => 'vendor_password_creation',
            'expires_at' => Carbon::now()->subMinutes(5),
        ]);

        // 4. Stale pending action
        $action = PendingAction::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'conversation_id' => $conversation->id,
            'action_type' => 'product_price',
            'payload' => ['price' => 2000],
            'payload_hash' => hash('sha256', 'payload'),
            'preview' => 'Update price to 2000',
            'action_token' => hash('sha256', uniqid()),
            'status' => 'pending',
            'expires_at' => Carbon::now()->subMinutes(5),
        ]);

        // Test dry-run: nothing changed
        $dryRunExit = Artisan::call('whatsapp:process-stuck-sessions', ['--dry-run' => true]);
        $this->assertEquals(0, $dryRunExit);

        $session->refresh();
        $this->assertEquals('started', $session->status);
        $delivery->refresh();
        $this->assertEquals('processing', $delivery->status);
        $token->refresh();
        $this->assertNull($token->revoked_at);
        $action->refresh();
        $this->assertEquals('pending', $action->status);

        // Test real run: changes applied
        $realRunExit = Artisan::call('whatsapp:process-stuck-sessions');
        $this->assertEquals(0, $realRunExit);

        $session->refresh();
        $this->assertEquals('expired', $session->status);

        $delivery->refresh();
        $this->assertEquals('pending', $delivery->status);
        $this->assertNull($delivery->claimed_at);

        $token->refresh();
        $this->assertNotNull($token->revoked_at);

        $action->refresh();
        $this->assertEquals('expired', $action->status);
    }

    public function test_cleanup_media_dry_run_and_execution(): void
    {
        Storage::fake('local');

        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '2348077889900',
        ]);

        $filePath = 'whatsapp/temp/' . uniqid() . '.jpg';
        Storage::disk('local')->put($filePath, 'fake-media-content');

        $media = WhatsAppMedia::create([
            'contact_id' => $contact->id,
            'media_id' => 'media_' . uniqid(),
            'file_path' => $filePath,
            'file_size' => 100,
            'mime_type' => 'image/jpeg',
            'purpose' => 'temporary',
            'status' => 'downloaded',
            'storage_disk' => 'local',
            'cleanup_at' => Carbon::now()->subMinutes(10),
        ]);

        // Dry run
        Artisan::call('whatsapp:cleanup-media', ['--dry-run' => true]);
        $media->refresh();
        $this->assertEquals('downloaded', $media->status);
        $this->assertTrue(Storage::disk('local')->exists($filePath));

        // Real run
        Artisan::call('whatsapp:cleanup-media');
        $media->refresh();
        $this->assertEquals('cleaned_up', $media->status);
        $this->assertNull($media->file_path);
        $this->assertFalse(Storage::disk('local')->exists($filePath));
    }
}
