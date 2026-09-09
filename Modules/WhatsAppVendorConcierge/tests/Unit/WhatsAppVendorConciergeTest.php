<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use App\Models\Vendor;
use App\Models\Store;
use App\Models\Module;
use App\Models\Zone;

class WhatsAppVendorConciergeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Setup configuration
        config([
            'whatsapp-vendor-concierge.api.phone_number_id' => '123456789',
            'whatsapp-vendor-concierge.api.access_token' => 'test_token',
            'whatsapp-vendor-concierge.webhook.verify_token' => 'test_verify_token',
            'whatsapp-vendor-concierge.webhook.app_secret' => 'test_secret',
        ]);
    }

    /** @test */
    public function it_creates_contact_and_normalizes_phone()
    {
        $contact = WhatsAppContact::findOrCreateByPhone('2348012345678', 'Test Vendor');

        $this->assertNotNull($contact);
        $this->assertEquals('2348012345678', $contact->phone_number);
        $this->assertEquals('Test Vendor', $contact->name);
        $this->assertNull($contact->vendor_id);
    }

    /** @test */
    public function it_finds_or_creates_active_conversation()
    {
        $contact = WhatsAppContact::create([
            'phone_number' => '2348012345678',
            'name' => 'Test Vendor',
        ]);

        $conversation = WhatsAppConversation::findOrCreateActive($contact->id);

        $this->assertNotNull($conversation);
        $this->assertEquals($contact->id, $conversation->contact_id);
        $this->assertEquals('welcome', $conversation->state);
        $this->assertTrue($conversation->is_active);

        // Fetching again should return the same conversation
        $sameConversation = WhatsAppConversation::findOrCreateActive($contact->id);
        $this->assertEquals($conversation->id, $sameConversation->id);
    }

    /** @test */
    public function it_logs_inbound_message_with_idempotency()
    {
        $contact = WhatsAppContact::create([
            'phone_number' => '2348012345678',
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'welcome',
        ]);

        $metaMessage = [
            'id' => 'wamid.HBgLMjM0ODAxMjM0NTY3OBUCABEYEjExMjIzMzQ0NTU2Njc3ODg5OQA=',
            'type' => 'text',
            'text' => ['body' => 'Hello'],
            'timestamp' => '1700000000',
        ];

        $message = WhatsAppMessage::logInbound($conversation->id, $metaMessage);

        $this->assertNotNull($message);
        $this->assertEquals($metaMessage['id'], $message->whatsapp_message_id);
        $this->assertEquals('inbound', $message->direction);
        $this->assertEquals('text', $message->type);
        $this->assertEquals('received', $message->status);

        // Duplicate message with same ID should not create new record
        $duplicate = WhatsAppMessage::logInbound($conversation->id, $metaMessage);
        $this->assertEquals($message->id, $duplicate->id);
        $this->assertEquals(1, WhatsAppMessage::count());
    }

    /** @test */
    public function it_tracks_onboarding_session_progress()
    {
        $contact = WhatsAppContact::create([
            'phone_number' => '2348012345678',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'business_basics',
            'collected_data' => [],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertTrue($session->canResume());
        $this->assertEquals(0, $session->completionPercentage());

        // Update step data
        $session->updateStepData('business_basics', [
            'f_name' => 'John',
            'l_name' => 'Doe',
            'store_name' => 'John Supermarket',
        ]);

        $this->assertEquals('John Supermarket', $session->getCollectedField('store_name'));
        $this->assertEquals('John', $session->getCollectedField('f_name'));
    }

    /** @test */
    public function it_validates_webhook_signature()
    {
        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $secret = 'test_secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $request = \Illuminate\Http\Request::create(
            '/api/webhooks/whatsapp',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => $signature],
            $payload
        );

        $gateway = new WhatsAppGateway();
        $this->assertTrue($gateway->validateWebhookSignature($request));

        // Invalid signature should fail
        $invalidRequest = \Illuminate\Http\Request::create(
            '/api/webhooks/whatsapp',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid_signature'],
            $payload
        );
        $this->assertFalse($gateway->validateWebhookSignature($invalidRequest));
    }
}