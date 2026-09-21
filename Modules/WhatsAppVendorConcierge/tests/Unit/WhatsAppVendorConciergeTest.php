<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Modules\WhatsAppVendorConcierge\tests\Hardening\ApplicationFixtureTestCase;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class WhatsAppVendorConciergeTest extends ApplicationFixtureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::create('subscription_packages', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id(); $table->string('package_name'); $table->decimal('price')->default(0);
            $table->integer('validity')->default(30); $table->boolean('status')->default(true);
            $table->string('module_type')->default('all'); $table->timestamps();
        });
        $module = \App\Models\Module::create(['module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);
        $zone = \App\Models\Zone::create(['name' => 'Lagos', 'status' => 1]);
        \Illuminate\Support\Facades\DB::table('module_zone')->insert(['module_id' => $module->id, 'zone_id' => $zone->id]);

        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');

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
        \Illuminate\Support\Facades\DB::table('business_settings')->insert(['key' => 'map_api_key_server', 'value' => 'fake-maps-key']);
        \Illuminate\Support\Facades\Http::fake(['maps.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
            'status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 6.52, 'lng' => 3.37]],
                'formatted_address' => '9A, Wing 1, Abiodun Fasakin Street, Lagos']],
        ])]);
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
        $category = \App\Models\Category::forceCreate(['name' => 'Demo category', 'parent_id' => 0, 'status' => 1, 'position' => 0]);

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

        $this->assertEquals('module_selection', $conversation->current_step);
        $this->assertEquals('module_selection', $session->current_step);
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
            ->method('sendButtonMessage')
            ->with(
                $contact->phone_number,
                $this->stringContains('Operating Hours'),
                $this->isType('array'),
                'MyTijaara Onboarding'
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
        $this->completeSubmissionFixture($session);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $sendAttempts = 0;
        $gateway->expects($this->exactly(2))
            ->method('sendTextMessage')
            ->with($contact->phone_number, $this->stringContains('Application Has Been Submitted'))
            ->willReturnCallback(function () use (&$sendAttempts): array {
                if (++$sendAttempts === 1) throw new \RuntimeException('Fixture transport outage');
                return ['messages' => [['id' => 'fixture-confirmation']]];
            });

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
        $vendorCount = \App\Models\Vendor::count();
        $storeCount = \App\Models\Store::count();
        $service->submitApplication($conversation, $contact, $session, $gateway);
        $this->assertSame($vendorCount, \App\Models\Vendor::count());
        $this->assertSame($storeCount, \App\Models\Store::count());
        $this->assertSame('submitted', $session->fresh()->status);
        $this->assertSame('onboarding_completed', $conversation->fresh()->state);
    }

    /** @test */
    public function it_enforces_mandatory_store_logo_and_rejects_skip()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);
        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        // 1. Text input without media (e.g. attempting to skip)
        $msgSkip = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'Skip'],
            'raw_text' => 'Skip',
        ]);
        $extracted = $extractMethod->invoke($service, $msgSkip, 'store_branding', null);
        $this->assertNull($extracted['logo_media_id']);

        $validation = $validateMethod->invoke($service, 'store_branding', $extracted);
        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);

        // 2. Valid image media record
        $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::create([
            'whatsapp_media_id' => 'media_logo_' . uniqid(),
            'mime_type' => 'image/png',
            'file_path' => 'store_logos/logo.png',
            'status' => 'downloaded',
        ]);

        $msgValid = new WhatsAppMessage([
            'type' => 'image',
            'content' => ['image' => ['id' => $media->whatsapp_media_id]],
            'media_id' => $media->id,
            'raw_text' => '',
        ]);
        $extractedValid = $extractMethod->invoke($service, $msgValid, 'store_branding', null);
        $this->assertEquals($media->id, $extractedValid['logo_media_id']);

        $validationValid = $validateMethod->invoke($service, 'store_branding', $extractedValid);
        $this->assertTrue($validationValid['valid']);
    }

    /** @test */
    public function it_validates_and_extracts_module_and_zone_selection()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);
        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        // Get an active module
        $module = \App\Models\Module::active()->notParcel()->first();
        $this->assertNotNull($module);
        if ($module) {
            $msgMod = new WhatsAppMessage([
                'type' => 'interactive',
                'content' => [
                    'interactive' => [
                        'button_reply' => ['id' => 'mod_' . $module->id, 'title' => $module->module_name],
                    ],
                ],
                'raw_text' => '',
            ]);
            $modData = $extractMethod->invoke($service, $msgMod, 'module_selection', null);
            $this->assertEquals($module->id, $modData['module_id']);
            $this->assertEquals($module->module_name, $modData['module_name']);

            $modVal = $validateMethod->invoke($service, 'module_selection', $modData);
            $this->assertTrue($modVal['valid']);
        }

        // Get an active zone
        $zone = \App\Models\Zone::first();
        $this->assertNotNull($zone);
        if ($zone) {
            $msgZone = new WhatsAppMessage([
                'type' => 'interactive',
                'content' => [
                    'interactive' => [
                        'button_reply' => ['id' => 'zone_' . $zone->id, 'title' => $zone->name],
                    ],
                ],
                'raw_text' => '',
            ]);
            $zoneData = $extractMethod->invoke($service, $msgZone, 'zone_selection', null);
            $this->assertEquals($zone->id, $zoneData['zone_id']);
            $this->assertEquals($zone->name, $zoneData['zone_name']);

            $zoneVal = $validateMethod->invoke($service, 'zone_selection', $zoneData);
            $this->assertTrue($zoneVal['valid']);
        }
    }

    /** @test */
    public function it_validates_separate_privacy_policy_acceptance()
    {
        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);
        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        // Accept privacy button
        $msgAccept = new WhatsAppMessage([
            'type' => 'interactive',
            'content' => [
                'interactive' => [
                    'button_reply' => ['id' => 'accept_privacy', 'title' => '✅ I Accept'],
                ],
            ],
            'raw_text' => '',
        ]);
        $dataAccept = $extractMethod->invoke($service, $msgAccept, 'privacy_acceptance', null);
        $this->assertTrue($dataAccept['privacy_accepted']);

        $valAccept = $validateMethod->invoke($service, 'privacy_acceptance', $dataAccept);
        $this->assertTrue($valAccept['valid']);

        // Decline privacy
        $msgDecline = new WhatsAppMessage([
            'type' => 'interactive',
            'content' => [
                'interactive' => [
                    'button_reply' => ['id' => 'decline_privacy', 'title' => '❌ Decline'],
                ],
            ],
            'raw_text' => '',
        ]);
        $dataDecline = $extractMethod->invoke($service, $msgDecline, 'privacy_acceptance', null);
        $this->assertFalse($dataDecline['privacy_accepted']);

        $valDecline = $validateMethod->invoke($service, 'privacy_acceptance', $dataDecline);
        $this->assertFalse($valDecline['valid']);
    }

    /** @test */
    public function it_handles_duplicate_vendor_phone_resiliently_on_submission()
    {
        $phone = '2349032617923';

        // Pre-create an unapproved/pending vendor with the same phone (simulating prior test)
        $existingVendor = \App\Models\Vendor::firstOrCreate(
            ['phone' => $phone],
            [
                'f_name' => 'Existing',
                'l_name' => 'Tester',
                'email' => 'prior_tester_' . uniqid() . '@example.com',
                'password' => bcrypt('PriorPass@123'),
                'status' => null,
            ]
        );

        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => $phone,
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
                'business_name' => 'Resilient Submission Store',
                'f_name' => 'Updated',
                'l_name' => 'Vendor',
                'email' => 'updated_' . uniqid() . '@example.com',
                'phone' => $phone,
                'password_hash' => bcrypt('SecurePass@123'),
                'latitude' => 6.5244,
                'longitude' => 3.3792,
                'address' => '50 Broad Street, Lagos',
                'business_plan' => 'commission-base',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $conversation->update(['onboarding_session_id' => $session->id]);
        $this->completeSubmissionFixture($session);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with($contact->phone_number, $this->stringContains('Application Has Been Submitted'));

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        // Must succeed without throwing 1062 Duplicate entry constraint violation
        $service->submitApplication($conversation, $contact, $session, $gateway);

        $session->refresh();
        $this->assertEquals('submitted', $session->status);
        $this->assertEquals($existingVendor->id, $session->vendor_id);

        $existingVendor->refresh();
        $this->assertNull($existingVendor->status, 'Vendor status must remain null for pending approval');
        $this->assertEquals('Updated', $existingVendor->f_name);
    }

    /** @test */
    public function it_enforces_max_10_rows_in_send_list_message()
    {
        $gateway = $this->getMockBuilder(WhatsAppGateway::class)
            ->onlyMethods(['sendMessage'])
            ->getMock();

        $capturedPayload = null;
        $gateway->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return ['messages' => [['id' => 'wamid.123']]];
            });

        // 14 rows total (exceeding Meta's 10 limit)
        $sections = [
            [
                'title' => 'Section 1',
                'rows' => [
                    ['id' => 'r1', 'title' => 'Row 1'],
                    ['id' => 'r2', 'title' => 'Row 2'],
                    ['id' => 'r3', 'title' => 'Row 3'],
                    ['id' => 'r4', 'title' => 'Row 4'],
                    ['id' => 'r5', 'title' => 'Row 5'],
                    ['id' => 'r6', 'title' => 'Row 6'],
                    ['id' => 'r7', 'title' => 'Row 7'],
                    ['id' => 'r8', 'title' => 'Row 8'],
                ],
            ],
            [
                'title' => 'Section 2',
                'rows' => [
                    ['id' => 'r9', 'title' => 'Row 9'],
                    ['id' => 'r10', 'title' => 'Row 10'],
                    ['id' => 'r11', 'title' => 'Row 11'],
                    ['id' => 'r12', 'title' => 'Row 12'],
                ],
            ],
        ];

        $gateway->sendListMessage('2348012345678', 'Test list', $sections);

        $this->assertNotNull($capturedPayload);
        $actionSections = $capturedPayload['interactive']['action']['sections'];

        $totalRows = 0;
        foreach ($actionSections as $sec) {
            $totalRows += count($sec['rows']);
        }

        $this->assertLessThanOrEqual(10, $totalRows, 'Total rows in list message must not exceed 10');
        $this->assertEquals(10, $totalRows);
    }

    /** @test */
    public function it_displays_categorized_edit_sections_within_row_limits()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'review_submit',
            'collected_data' => ['business_name' => 'Categorized Edit Store'],
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
            ->method('sendListMessage')
            ->with(
                $contact->phone_number,
                $this->stringContains('category of your application'),
                $this->callback(function ($sections) {
                    // Category list must have exactly 4 rows (<= 10)
                    return count($sections[0]['rows']) === 4;
                }),
                'Edit Application'
            );

        $manager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
        $manager->handleEditApplication($conversation, $contact, $gateway);
    }

    /** @test */
    public function it_routes_category_selection_to_sublist_with_few_rows()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'onboarding_active',
            'current_step' => 'review_submit',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendListMessage')
            ->with(
                $contact->phone_number,
                $this->stringContains('business detail'),
                $this->callback(function ($sections) {
                    // Business identity includes the optional cover photo.
                    return count($sections[0]['rows']) === 4;
                }),
                'Edit Field'
            );

        $manager = app(\Modules\WhatsAppVendorConcierge\app\Services\ConversationManager::class);
        $manager->handleListResponse($conversation, $contact, 'edit_cat_business', '🏪 Business Identity', $gateway);
    }

    /** @test */
    public function it_generates_secure_password_token_and_sends_cta_url()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'account_password',
            'collected_data' => ['business_name' => 'Secure Pass Store'],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'account_password',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendCtaUrlMessage')
            ->with(
                $contact->phone_number,
                $this->stringContains('passwords cannot be entered in WhatsApp'),
                'Set Password 🔐',
                $this->stringContains('/whatsapp/onboarding/password/')
            );

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $service->sendStepPrompt($conversation, $contact, 'account_password', $gateway);

        $session->refresh();
        $token = \Modules\WhatsAppVendorConcierge\app\Models\CredentialToken::where('onboarding_session_id', $session->id)->firstOrFail();
        $this->assertNotEmpty($token->token_hash);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertArrayNotHasKey('_pwd_token_hash', $session->collected_data);
    }

    /** @test */
    public function it_rejects_plaintext_passwords_sent_in_whatsapp_chat()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'account_password',
            'collected_data' => ['business_name' => 'Reject Plaintext Store'],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'account_password',
        ]);

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $reflection = new \ReflectionClass($service);
        $extractMethod = $reflection->getMethod('extractStepData');
        $extractMethod->setAccessible(true);
        $validateMethod = $reflection->getMethod('validateStep');
        $validateMethod->setAccessible(true);

        // User attempts to type a password into WhatsApp chat
        $msg = new WhatsAppMessage([
            'type' => 'text',
            'content' => ['text' => 'MySecretPassword123!'],
            'raw_text' => 'MySecretPassword123!',
        ]);

        $extracted = $extractMethod->invoke($service, $msg, 'account_password', $contact);
        $this->assertFalse($extracted['has_password']);
        $this->assertTrue($extracted['plaintext_sent']);

        $validation = $validateMethod->invoke($service, 'account_password', $extracted);
        $this->assertFalse($validation['valid']);
    }

    /** @test */
    public function it_renders_and_submits_secure_password_over_https()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'test_wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);
        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'account_password',
            'collected_data' => [
                'business_name' => 'HTTPS Store',
                'email' => 'https_store@mytijaara.test',
            ],
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'account_password',
        ]);

        $service = app(\Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService::class);
        $passwordUrl = $service->generateSecurePasswordUrl($session);

        // Extract token from URL
        preg_match('/\/whatsapp\/onboarding\/password\/([a-f0-9]+)/', $passwordUrl, $matches);
        $token = $matches[1];

        // 1. GET page
        $response = $this->get('/whatsapp/onboarding/password/' . $token);
        $response->assertStatus(200);
        $response->assertSee('Create Dashboard Password');
        $response->assertSee('HTTPS Store');

        // 2. POST valid strong password
        $postResponse = $this->post('/whatsapp/onboarding/password/' . $token, [
            'password' => 'SecureP@ss2026',
            'password_confirmation' => 'SecureP@ss2026',
        ]);
        $postResponse->assertStatus(200);
        $postResponse->assertSee('Password Created!');

        // 3. Verify session was updated and step advanced to store_branding
        $session->refresh();
        $this->assertTrue($session->collected_data['has_password']);
        $this->assertNotEmpty($session->collected_data['password_hash']);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('SecureP@ss2026', $session->collected_data['password_hash']));
        $this->assertEquals('store_branding', $session->current_step);

        $conversation->refresh();
        $this->assertEquals('store_branding', $conversation->current_step);

        // 4. Verify token was single-use and is now invalidated
        $reuseResponse = $this->get('/whatsapp/onboarding/password/' . $token);
        $reuseResponse->assertStatus(200);
        $reuseResponse->assertSee('Link Expired or Used');
    }

    private function completeSubmissionFixture(OnboardingSession $session): void
    {
        $module = \App\Models\Module::create(['module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);
        $zone = \App\Models\Zone::create(['name' => 'Lagos', 'status' => 1]);
        \Illuminate\Support\Facades\DB::table('module_zone')->insert(['module_id' => $module->id, 'zone_id' => $zone->id]);
        $image = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 512, 512);
        $path = 'whatsapp/logos/test.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, file_get_contents($image->getRealPath()));
        $media = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia::create([
            'whatsapp_media_id' => 'logo-'.$session->id, 'mime_type' => 'image/png', 'status' => 'processed',
            'file_path' => $path, 'storage_disk' => 'local',
            'metadata' => ['contact_id' => $session->contact_id, 'purpose' => 'logo'],
        ]);
        $session->update(['collected_data' => array_merge($session->collected_data, [
            'module_id' => $module->id, 'zone_id' => $zone->id, 'logo_media_id' => $media->id,
            'privacy_accepted' => true, 'delivery_time' => '20-40 min',
        ])]);
    }
}
