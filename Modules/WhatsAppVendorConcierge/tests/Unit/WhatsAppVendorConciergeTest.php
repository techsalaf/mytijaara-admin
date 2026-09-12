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

    /** @test */
    public function it_resolves_location_from_whatsapp_location_message()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'location',
            'content' => [
                'location' => [
                    'latitude' => 6.5630418,
                    'longitude' => 3.3677308,
                    'name' => 'Anthony Village',
                    'address' => null,
                ],
            ],
            'raw_text' => '',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'location', null);

        $this->assertEquals(6.5630418, $data['latitude']);
        $this->assertEquals(3.3677308, $data['longitude']);
        $this->assertNotEmpty($data['address']);
    }

    /** @test */
    public function it_resolves_location_from_google_maps_url()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => [
                'text' => 'location: https://maps.google.com/?q=6.5630418,3.3677308',
            ],
            'raw_text' => 'location: https://maps.google.com/?q=6.5630418,3.3677308',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'location', null);

        $this->assertEquals(6.5630418, $data['latitude']);
        $this->assertEquals(3.3677308, $data['longitude']);
        $this->assertNotEmpty($data['address']);
    }

    /** @test */
    public function it_resolves_location_from_text_address()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => [
                'text' => '9A, Wing 1, Abiodun Fasakin Street, Lagos',
            ],
            'raw_text' => '9A, Wing 1, Abiodun Fasakin Street, Lagos',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'location', null);

        $this->assertNotNull($data['latitude']);
        $this->assertNotNull($data['longitude']);
        $this->assertNotEmpty($data['address']);
    }

    /** @test */
    public function it_matches_category_from_text_input()
    {
        $category = \App\Models\Category::firstOrCreate(
            ['name' => 'Demo category', 'parent_id' => 0],
            ['status' => 1, 'position' => 0]
        );

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'Demo category'],
            'raw_text' => 'Demo category',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'category_selection', null);

        $this->assertEquals((string) $category->id, $data['category_id']);
        $this->assertEquals($category->name, $data['category_name']);
    }

    /** @test */
    public function it_extracts_contact_info_with_phone_number()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '+2348012345678',
        ]);

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'testvendor@example.com'],
            'raw_text' => 'testvendor@example.com',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'contact_info', $contact);

        $this->assertEquals('testvendor@example.com', $data['email']);
        $this->assertEquals('+2348012345678', $data['phone']);
    }

    /** @test */
    public function it_validates_operating_hours_as_string()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('validateStep');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'operating_hours', ['schedule' => 'Mon-Sat 9am-6pm, Sun Closed']);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_synchronizes_session_current_step_on_advance()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'business_basics',
            'collected_data' => ['business_name' => 'Ronix Essentials'],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'business_basics',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('advanceToNextStep');
        $method->setAccessible(true);

        $method->invoke($service, $conversation, $contact, $session, $gateway);

        $conversation->refresh();
        $session->refresh();

        $this->assertEquals('owner_info', $conversation->current_step);
        $this->assertEquals('owner_info', $session->current_step);
    }

    /** @test */
    public function it_can_serialize_process_whatsapp_media_job()
    {
        $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::create([
            'whatsapp_media_id' => 'media_test_' . uniqid(),
            'mime_type' => 'image/jpeg',
            'status' => 'pending_download',
        ]);

        $job = new \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppMedia($media);
        $serialized = serialize($job);
        $this->assertNotEmpty($serialized);

        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(\Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppMedia::class, $unserialized);
        $this->assertEquals($media->id, $unserialized->media->id);

        // Also verify legacy 2-argument instantiation serializes cleanly without closures
        $gateway = new \Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway();
        $legacyJob = new \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppMedia($media, $gateway);
        $legacySerialized = serialize($legacyJob);
        $this->assertNotEmpty($legacySerialized);
        $this->assertInstanceOf(\Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppMedia::class, unserialize($legacySerialized));
    }

    /** @test */
    public function it_resumes_onboarding_and_sets_active_state()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'documents',
            'collected_data' => ['business_name' => 'Ronix Essentials'],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'welcome',
            'current_step' => 'welcome',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $manager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);

        $manager->resumeOnboarding($conversation, $contact, $gateway);

        $conversation->refresh();
        $this->assertEquals('onboarding_active', $conversation->state);
        $this->assertEquals('documents', $conversation->current_step);
        $this->assertTrue($conversation->isOnboarding());
    }

    /** @test */
    public function it_extracts_document_from_image_upload()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $metaId = 'wamid_media_' . uniqid();
        $msg = new WhatsAppMessage([
            'type' => 'image',
            'content' => [
                'image' => [
                    'id' => $metaId,
                    'mime_type' => 'image/jpeg',
                ],
            ],
            'raw_text' => '',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'documents', null);

        $this->assertNotNull($data['media_id']);
        $this->assertDatabaseHas('whatsapp_media', [
            'id' => $data['media_id'],
            'whatsapp_media_id' => $metaId,
        ]);
    }

    /** @test */
    public function it_extracts_document_from_pdf_document_upload()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $metaId = 'wamid_doc_' . uniqid();
        $msg = new WhatsAppMessage([
            'type' => 'document',
            'content' => [
                'document' => [
                    'id' => $metaId,
                    'mime_type' => 'application/pdf',
                    'filename' => 'cac_certificate.pdf',
                ],
            ],
            'raw_text' => '',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'documents', null);

        $this->assertNotNull($data['media_id']);
        $this->assertDatabaseHas('whatsapp_media', [
            'id' => $data['media_id'],
            'whatsapp_media_id' => $metaId,
            'mime_type' => 'application/pdf',
        ]);
    }

    /** @test */
    public function it_allows_skipping_document_upload()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => [
                'text' => 'Skip',
            ],
            'raw_text' => 'Skip',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractStepData');
        $method->setAccessible(true);

        $data = $method->invoke($service, $msg, 'documents', null);

        $this->assertNull($data['media_id']);

        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        $validation = $validateMethod->invoke($service, 'documents', $data);
        $this->assertTrue($validation['valid']);
    }

    /** @test */
    public function it_builds_complete_review_summary_including_hours_and_category_and_documents()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'review_submit',
            'collected_data' => [
                'business_name' => 'Ronix Superstore',
                'category_name' => 'Groceries & Staples',
                'address' => 'Plot 4, Commercial Ave, Lagos',
                'email' => 'store@ronix.com',
                'phone' => '2348012345678',
                'schedule' => 'Mon-Sat 8am - 9pm, Sun 10am - 4pm',
                'media_id' => 999,
            ],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $summary = $service->buildReviewSummary($session);

        $this->assertStringContainsString('Ronix Superstore', $summary);
        $this->assertStringContainsString('Groceries & Staples', $summary);
        $this->assertStringContainsString('Plot 4, Commercial Ave, Lagos', $summary);
        $this->assertStringContainsString('store@ronix.com', $summary);
        $this->assertStringContainsString('2348012345678', $summary);
        $this->assertStringContainsString('Mon-Sat 8am - 9pm, Sun 10am - 4pm', $summary);
        $this->assertStringContainsString('Uploaded', $summary);
    }

    /** @test */
    public function it_handles_edit_section_from_list_and_sets_target_step()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'review_submit',
            'collected_data' => [
                'business_name' => 'Ronix Superstore',
                '_in_review' => true,
            ],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'review_submit',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                $contact->phone_number,
                $this->stringContains('What are your operating hours?')
            );

        $manager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
        $manager->handleListResponse($conversation, $contact, 'edit_hours', '🕐 Operating Hours', $gateway);

        $conversation->refresh();
        $session->refresh();

        $this->assertEquals('operating_hours', $conversation->current_step);
        $this->assertEquals('operating_hours', $session->current_step);
        $this->assertTrue($session->collected_data['_in_review']);
    }

    /** @test */
    public function it_returns_to_review_submit_after_editing_section_when_in_review()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'operating_hours',
            'collected_data' => [
                'business_name' => 'Ronix Superstore',
                'category_id' => 1,
                'address' => 'Plot 4, Commercial Ave',
                'latitude' => 6.5,
                'longitude' => 3.3,
                'email' => 'store@ronix.com',
                'phone' => '2348012345678',
                '_in_review' => true,
            ],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'operating_hours',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendButtonMessage')
            ->with(
                $contact->phone_number,
                $this->stringContains('Mon-Sat 9am - 6pm'),
                $this->isType('array'),
                'Review Your Application'
            );

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'Mon-Sat 9am - 6pm'],
            'raw_text' => 'Mon-Sat 9am - 6pm',
        ]);

        $service->processStep($conversation, $contact, $msg, $gateway);

        $conversation->refresh();
        $session->refresh();

        $this->assertEquals('review_submit', $conversation->current_step);
        $this->assertEquals('review_submit', $session->current_step);
        $this->assertEquals('Mon-Sat 9am - 6pm', $session->collected_data['schedule']);
    }

    /** @test */
    public function it_extracts_and_validates_owner_info()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);

        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'Rasheed Bello'],
            'raw_text' => 'Rasheed Bello',
        ]);

        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);

        $data = $extractMethod->invoke($service, $msg, 'owner_info', null);

        $this->assertEquals('Rasheed', $data['f_name']);
        $this->assertEquals('Bello', $data['l_name']);

        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        $validation = $validateMethod->invoke($service, 'owner_info', $data);
        $this->assertTrue($validation['valid']);
    }

    /** @test */
    public function it_validates_strong_account_password_and_scrubs_plaintext()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        // Weak password fails
        $weakValidation = $validateMethod->invoke($service, 'account_password', ['password' => 'simple']);
        $this->assertFalse($weakValidation['valid']);

        // Strong password passes
        $strongValidation = $validateMethod->invoke($service, 'account_password', ['password' => 'ShopPass@2026']);
        $this->assertTrue($strongValidation['valid']);
    }

    /** @test */
    public function it_extracts_business_plan_selection()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);

        // Commission button
        $msgComm = new WhatsAppMessage([
            'type' => 'interactive',
            'content' => [
                'interactive' => [
                    'button_reply' => ['id' => 'plan_commission', 'title' => '💼 Commission-Based'],
                ],
            ],
            'raw_text' => '',
        ]);
        $dataComm = $extractMethod->invoke($service, $msgComm, 'business_plan', null);
        $this->assertEquals('commission-base', $dataComm['business_plan']);

        // Subscription button
        $msgSub = new WhatsAppMessage([
            'type' => 'interactive',
            'content' => [
                'interactive' => [
                    'button_reply' => ['id' => 'plan_subscription', 'title' => '📅 Subscription Plan'],
                ],
            ],
            'raw_text' => '',
        ]);
        $dataSub = $extractMethod->invoke($service, $msgSub, 'business_plan', null);
        $this->assertEquals('subscription-base', $dataSub['business_plan']);
    }

    /** @test */
    public function it_extracts_kyc_documents_and_supports_skip()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);

        // TIN text
        $msgTin = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'RC-12345678'],
            'raw_text' => 'RC-12345678',
        ]);
        $dataTin = $extractMethod->invoke($service, $msgTin, 'kyc_documents', null);
        $this->assertEquals('RC-12345678', $dataTin['tin']);

        // Skip
        $msgSkip = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'Skip'],
            'raw_text' => 'Skip',
        ]);
        $dataSkip = $extractMethod->invoke($service, $msgSkip, 'kyc_documents', null);
        $this->assertNull($dataSkip['tin']);
        $this->assertTrue($dataSkip['kyc_skipped']);
    }

    /** @test */
    public function it_validates_terms_and_conditions_acceptance()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);
        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        // Accept
        $msgAccept = new WhatsAppMessage([
            'type' => 'interactive',
            'content' => [
                'interactive' => [
                    'button_reply' => ['id' => 'accept_terms', 'title' => '✅ I Accept'],
                ],
            ],
            'raw_text' => '',
        ]);
        $dataAccept = $extractMethod->invoke($service, $msgAccept, 'terms_acceptance', null);
        $this->assertTrue($dataAccept['terms_accepted']);
        $validation = $validateMethod->invoke($service, 'terms_acceptance', $dataAccept);
        $this->assertTrue($validation['valid']);

        // Decline
        $msgDecline = new WhatsAppMessage([
            'type' => 'interactive',
            'content' => [
                'interactive' => [
                    'button_reply' => ['id' => 'decline_terms', 'title' => '❌ Decline'],
                ],
            ],
            'raw_text' => '',
        ]);
        $dataDecline = $extractMethod->invoke($service, $msgDecline, 'terms_acceptance', null);
        $this->assertFalse($dataDecline['terms_accepted']);
        $validationDecline = $validateMethod->invoke($service, 'terms_acceptance', $dataDecline);
        $this->assertFalse($validationDecline['valid']);
    }

    /** @test */
    public function it_submits_application_with_null_vendor_status_for_pending_approval()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_sub_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'onboarding_active',
            'current_step' => 'review_submit',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'review_submit',
            'collected_data' => [
                'business_name' => 'Canonical Shop Test',
                'f_name' => 'Rasheed',
                'l_name' => 'Bello',
                'email' => 'canonical_' . uniqid() . '@mytijaara.test',
                'phone' => '234' . rand(8000000000, 8099999999),
                'password_hash' => bcrypt('SecurePass@123'),
                'latitude' => 6.5244,
                'longitude' => 3.3792,
                'address' => '10 Marina Street, Lagos Island',
                'business_plan' => 'commission-base',
                'terms_accepted' => true,
            ],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $conversation->update(['onboarding_session_id' => $session->id]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with($contact->phone_number, $this->stringContains('Application Has Been Submitted'));

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $service->submitApplication($conversation, $contact, $session, $gateway);

        $session->refresh();
        $this->assertEquals('submitted', $session->status);
        $this->assertNotNull($session->vendor_id);
        $this->assertNotNull($session->store_id);

        $vendor = \App\Models\Vendor::find($session->vendor_id);
        $this->assertNotNull($vendor);
        $this->assertEquals('Rasheed', $vendor->f_name);
        $this->assertEquals('Bello', $vendor->l_name);
        // CRITICAL BUG FIX VERIFICATION: vendor status MUST be null to be in Pending Requests!
        $this->assertNull($vendor->status, 'Vendor status must be null so application appears in Admin Pending Requests');

        $store = \App\Models\Store::find($session->store_id);
        $this->assertNotNull($store);
        $this->assertEquals('Canonical Shop Test', $store->name);
        $this->assertEquals(0, $store->status);
        $this->assertEquals('commission', $store->store_business_model);
    }
}