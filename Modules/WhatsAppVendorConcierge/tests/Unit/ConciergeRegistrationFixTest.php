<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Modules\WhatsAppVendorConcierge\tests\Hardening\ApplicationFixtureTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\DTOs\VendorApplicationDTO;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\VendorApplicationService;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class ConciergeRegistrationFixTest extends ApplicationFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        if (!\Illuminate\Support\Facades\Schema::hasTable('subscription_packages')) {
            \Illuminate\Support\Facades\Schema::create('subscription_packages', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('package_name');
                $table->decimal('price')->default(0);
                $table->integer('validity')->default(30);
                $table->boolean('status')->default(true);
                $table->string('module_type')->default('all');
                $table->timestamps();
            });
        }
    }

    public function test_file_materialization_accepts_public_disk_and_downloaded_status(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348011223344',
            'phone_number' => '2348011223344',
            'display_name' => 'Tester',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'store_branding',
            'collected_data' => [],
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'store_branding',
        ]);

        // Put an image file on public disk
        $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('whatsapp/media/test_logo.png', $imageContent);

        $media = WhatsAppMedia::create([
            'whatsapp_media_id' => 'wa_media_test_123',
            'file_path' => 'whatsapp/media/test_logo.png',
            'storage_disk' => 'public',
            'status' => 'downloaded', // test that 'downloaded' is accepted
            'mime_type' => 'image/png',
            'file_size' => strlen($imageContent),
        ]);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.test_123',
            'direction' => 'inbound',
            'type' => 'image',
            'media_id' => $media->id,
            'metadata' => [
                'registration_session_id' => $session->id,
                'registration_contact_id' => $contact->id,
            ],
        ]);

        $dto = new VendorApplicationDTO(
            f_name: 'Test',
            l_name: 'User',
            phone: '2348011223344',
            email: 'test@example.com',
            business_name: 'Test Store',
            address: '123 Main St',
            latitude: 6.5,
            longitude: 3.3,
            zone_id: 1,
            module_id: 1,
            logo: (string) $media->id,
            cover_photo: null,
            metadata: [
                'session_id' => $session->id,
                'contact_id' => $contact->id,
            ]
        );

        $service = app(VendorApplicationService::class);
        $reflection = new \ReflectionClass($service);
        $fileMethod = $reflection->getMethod('file');
        $fileMethod->setAccessible(true);

        $tempFiles = [];
        $result = $fileMethod->invokeArgs($service, [$dto->logo, 'logo', $dto, &$tempFiles]);

        $this->assertInstanceOf(UploadedFile::class, $result);
        $this->assertNotEmpty($tempFiles);

        foreach ($tempFiles as $t) {
            @unlink($t);
        }
    }

    public function test_subscription_package_auto_switches_to_commission_when_empty_or_requested(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348055667788',
            'phone_number' => '2348055667788',
            'display_name' => 'Plan Tester',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'subscription_package',
            'collected_data' => [
                'business_plan' => 'subscription-base',
            ],
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'subscription_package',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->atLeastOnce())
            ->method('sendTextMessage');

        $service = app(VendorOnboardingService::class);

        // User says "commission based" or "where is the list"
        $message = new WhatsAppMessage([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.chat_reply',
            'direction' => 'inbound',
            'type' => 'text',
            'raw_text' => 'I want commission based',
            'content' => ['text' => 'I want commission based'],
        ]);

        $service->processStep($conversation, $contact, $message, $gateway);

        $session->refresh();
        $this->assertEquals('commission-base', $session->collected_data['business_plan']);
        $this->assertEquals('terms_acceptance', $conversation->fresh()->current_step);
    }

    public function test_conversational_confusion_handled_helpfully_during_onboarding(): void
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348099887766',
            'phone_number' => '2348099887766',
            'display_name' => 'Confused Vendor',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'store_branding',
            'collected_data' => [],
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'onboarding_active',
            'current_step' => 'store_branding',
        ]);

        $sentMessages = [];
        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->atLeastOnce())
            ->method('sendTextMessage')
            ->willReturnCallback(function ($phone, $text) use (&$sentMessages) {
                $sentMessages[] = $text;
                return ['messages' => [['id' => 'mid_' . uniqid()]]];
            });

        $service = app(VendorOnboardingService::class);

        // User expresses confusion or asks what a logo is
        $message = new WhatsAppMessage([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.confused_msg',
            'direction' => 'inbound',
            'type' => 'text',
            'raw_text' => 'I don\'t understand, what is a store logo?',
            'content' => ['text' => 'I don\'t understand, what is a store logo?'],
        ]);

        $service->processStep($conversation, $contact, $message, $gateway);

        // Confirm that the bot responded with the helpful guidance and re-prompted
        $this->assertNotEmpty($sentMessages);
        $fullText = implode("\n", $sentMessages);
        $this->assertStringContainsString('Store Logo', $fullText);
        $this->assertStringContainsString('photo', $fullText);
    }
}
