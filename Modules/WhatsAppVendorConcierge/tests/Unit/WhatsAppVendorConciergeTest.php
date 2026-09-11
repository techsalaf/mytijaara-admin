<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class WhatsAppVendorConciergeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp-vendor-concierge.api.phone_number_id' => '123456789',
            'whatsapp-vendor-concierge.api.access_token' => 'test_token',
            'whatsapp-vendor-concierge.api.app_secret' => 'test_secret',
            'whatsapp-vendor-concierge.webhook.verify_token' => 'test_verify_token',
            'whatsapp-vendor-concierge.webhook.signature_header' => 'X-Hub-Signature-256',
        ]);
    }

    /** @test */
    public function it_creates_contact_and_normalizes_phone()
    {
        $uniqueId = 'test_wa_' . uniqid();
        $phone = '234' . rand(8000000000, 8099999999);
        $contact = WhatsAppContact::findOrCreateByWhatsAppId($uniqueId, $phone, ['name' => 'Test Vendor']);

        $this->assertNotNull($contact);
        $this->assertEquals($phone, $contact->phone_number);
        $this->assertEquals('Test Vendor', $contact->display_name);
        $this->assertNull($contact->vendor_id);
    }

    /** @test */
    public function it_finds_or_creates_active_conversation()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
            'display_name' => 'Test Vendor 2',
        ]);

        $conversation = WhatsAppConversation::getOrCreateActive($contact->id);

        $this->assertNotNull($conversation);
        $this->assertEquals($contact->id, $conversation->contact_id);
        $this->assertEquals('new', $conversation->state);

        $sameConversation = WhatsAppConversation::getOrCreateActive($contact->id);
        $this->assertEquals($conversation->id, $sameConversation->id);
    }

    /** @test */
    public function it_logs_inbound_message_with_idempotency()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'welcome',
        ]);

        $msgId = 'wamid.test_' . uniqid();
        $metaMessage = [
            'id' => $msgId,
            'type' => 'text',
            'text' => ['body' => 'Hello'],
            'timestamp' => '1700000000',
        ];

        $message = WhatsAppMessage::logInbound($conversation->id, $metaMessage, ['contacts' => []]);

        $this->assertNotNull($message);
        $this->assertEquals($msgId, $message->whatsapp_message_id);
        $this->assertEquals('inbound', $message->direction);
        $this->assertEquals('text', $message->type);
        $this->assertEquals('delivered', $message->status);

        $duplicate = WhatsAppMessage::logInbound($conversation->id, $metaMessage, ['contacts' => []]);
        $this->assertEquals($message->id, $duplicate->id);
    }

    /** @test */
    public function it_tracks_onboarding_session_progress()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
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
        $this->assertEquals('business_basics', $session->current_step);

        $session->updateData([
            'f_name' => 'John',
            'l_name' => 'Doe',
            'store_name' => 'John Supermarket',
        ]);

        $this->assertEquals('John Supermarket', $session->collected_data['store_name']);
        $this->assertEquals('John', $session->collected_data['f_name']);
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

    /** @test */
    public function it_transitions_conversation_state_from_new_on_welcome()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $conversation = WhatsAppConversation::getOrCreateActive($contact->id);
        $this->assertEquals('new', $conversation->state);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())->method('sendButtonMessage');

        $manager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
        $manager->handleWelcome($conversation, $contact, $gateway);

        $conversation->refresh();
        $this->assertEquals('welcome', $conversation->state);
    }

    /** @test */
    public function it_handles_button_reply_and_starts_onboarding()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $conversation = WhatsAppConversation::getOrCreateActive($contact->id);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())->method('sendTextMessage');

        $manager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
        $manager->handleButtonResponse($conversation, $contact, 'open_shop', $gateway);

        $conversation->refresh();
        $this->assertEquals('onboarding_active', $conversation->state);
        $this->assertEquals('business_basics', $conversation->current_step);
        $this->assertNotNull($conversation->onboarding_session_id);
    }
}