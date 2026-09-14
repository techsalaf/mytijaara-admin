<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Events\VendorApplicationStatusChanged;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;
use Modules\WhatsAppVendorConcierge\app\Listeners\SendWhatsAppStatusNotificationOnDomainEvent;
use Modules\WhatsAppVendorConcierge\app\Models\NotificationDelivery;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class NotificationConcurrencyTest extends HardeningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::create('cache', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        \Illuminate\Support\Facades\Schema::dropIfExists('stores');
        \Illuminate\Support\Facades\Schema::dropIfExists('vendors');

        \Illuminate\Support\Facades\Schema::create('vendors', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('phone')->nullable();
            $table->integer('status')->nullable()->default(1);
            $table->text('rejection_note')->nullable();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('stores', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('translations', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('storages', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->string('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    public function test_stale_denial_is_not_sent_to_an_approved_vendor(): void
    {
        Vendor::unguard(); Store::unguard();
        Vendor::create(['id' => 301, 'status' => 1]);
        Store::create(['id' => 301, 'vendor_id' => 301]);
        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->never())->method('sendTemplateMessage');
        $gateway->expects($this->never())->method('sendButtonMessage');
        (new SendVendorStatusNotification(301, 'denied', 'Outdated reason'))->handle($gateway);
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_decision_state_is_synchronized_when_delivery_fails(): void
    {
        Vendor::unguard(); Store::unguard();
        Vendor::create(['id' => 302, 'status' => 0]);
        Store::create(['id' => 302, 'vendor_id' => 302]);
        $contact = WhatsAppContact::create(['whatsapp_id' => '2348000000302', 'phone_number' => '2348000000302', 'vendor_id' => 302]);
        $conversation = WhatsAppConversation::create(['contact_id' => $contact->id, 'state' => 'onboarding_completed', 'current_step' => 'review_submit']);
        config(['whatsapp-vendor-concierge.messaging.templates.denied' => 'test_denied']);
        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->method('sendTemplateMessage')->willReturn(['error' => ['code' => 132001]]);
        try { (new SendVendorStatusNotification(302, 'denied', 'Missing document'))->handle($gateway); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('rejected by Meta', $e->getMessage()); }
        $this->assertSame('welcome', $conversation->fresh()->state);
        $this->assertNull($conversation->fresh()->current_step);
        $this->assertSame('rejected_applicant', $contact->fresh()->contact_type);
        $this->assertSame('failed', NotificationDelivery::first()->status);
    }

    public function test_domain_event_triggers_status_notification_job(): void
    {
        Queue::fake();

        $vendor = new Vendor();
        $vendor->id = 101;
        $vendor->f_name = 'Olumide';
        $vendor->phone = '2348055551111';

        $store = new Store();
        $store->id = 201;
        $store->name = 'Olumide Logistics';
        $store->setRelation('vendor', $vendor);

        $event = new VendorApplicationStatusChanged($store, $vendor, 'approved');
        (new SendWhatsAppStatusNotificationOnDomainEvent())->handle($event);

        Queue::assertPushed(SendVendorStatusNotification::class, function ($job) {
            return $job->storeId === 201 && $job->status === 'approved';
        });
    }

    public function test_concurrent_workers_prevent_duplicate_notification_delivery(): void
    {
        // 1. Setup vendor, store, contact, conversation
        $vendor = new Vendor();
        $vendor->id = 102;
        $vendor->f_name = 'Bisi';
        $vendor->phone = '2348055552222';

        $store = new Store();
        $store->id = 202;
        $store->name = 'Bisi Fabrics';
        $store->setRelation('vendor', $vendor);

        // Store in mock relations
        Store::unguard();
        Vendor::unguard();
        $dbVendor = Vendor::create(['id' => 102, 'f_name' => 'Bisi', 'phone' => '2348055552222']);
        $dbStore = Store::create(['id' => 202, 'name' => 'Bisi Fabrics', 'vendor_id' => 102]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348055552222',
            'phone_number' => '2348055552222',
            'vendor_id' => $vendor->id,
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'onboarding_active',
            'current_step' => 'review_submit',
        ]);

        // Recent inbound message establishes 24-hour customer window
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.inbound.202',
            'direction' => 'inbound',
            'type' => 'text',
            'raw_text' => 'Hello',
            'created_at' => now(),
        ]);

        $sendCount = 0;
        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->willReturnCallback(function () use (&$sendCount) {
                $sendCount++;
                return ['messages' => [['id' => 'wamid.test.' . $sendCount]]];
            });

        // Two identical jobs for the same approval transition
        $version = hash('sha256', 'stable-test-version');
        $job1 = new SendVendorStatusNotification($store->id, 'approved', null, $version);
        $job2 = new SendVendorStatusNotification($store->id, 'approved', null, $version);

        // Worker 1 runs
        $job1->handle($gateway);
        $this->assertEquals(1, $sendCount, 'First worker must send the message');

        // Worker 2 runs concurrently for the same version
        $job2->handle($gateway);
        $this->assertEquals(1, $sendCount, 'Second worker must NOT double send under concurrency');

        // Check delivery record state
        $delivery = NotificationDelivery::where('store_id', $store->id)
            ->where('notification_type', 'approved')
            ->first();

        $this->assertNotNull($delivery);
        $this->assertEquals('sent', $delivery->status);
        $this->assertEquals(1, $delivery->attempt_count);
        $this->assertEquals('text', $delivery->channel);
        $this->assertNotNull($delivery->provider_message_id);
    }

    public function test_outside_24h_window_dispatches_template_with_components(): void
    {
        $vendor = new Vendor();
        $vendor->id = 103;
        $vendor->f_name = 'Tunde';
        $vendor->phone = '2348055553333';

        $store = new Store();
        $store->id = 203;
        $store->name = 'Tunde Electronics';
        $store->setRelation('vendor', $vendor);

        Store::unguard();
        Vendor::unguard();
        Vendor::create(['id' => 103, 'f_name' => 'Tunde', 'phone' => '2348055553333']);
        Store::create(['id' => 203, 'name' => 'Tunde Electronics', 'vendor_id' => 103]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348055553333',
            'phone_number' => '2348055553333',
            'vendor_id' => $vendor->id,
            'metadata' => ['locale' => 'en_US'],
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'onboarding_active',
            'current_step' => 'review_submit',
        ]);

        // Inbound message is older than 24 hours (outside service window)
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.inbound.203',
            'direction' => 'inbound',
            'type' => 'text',
            'raw_text' => 'Hello',
            'created_at' => now()->subHours(25),
        ]);

        config([
            'whatsapp-vendor-concierge.messaging.templates.approved' => 'vendor_application_approved',
            'whatsapp-vendor-concierge.messaging.template_locales.approved' => 'en',
        ]);

        $templateSent = false;
        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTemplateMessage')
            ->with(
                '2348055553333',
                'vendor_application_approved',
                [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Tunde Electronics']]]],
                'en'
            )
            ->willReturnCallback(function () use (&$templateSent) {
                $templateSent = true;
                return ['messages' => [['id' => 'wamid.template.103']]];
            });

        $job = new SendVendorStatusNotification($store->id, 'approved', null, 'version-outside-window');
        $job->handle($gateway);

        $this->assertTrue($templateSent);

        $delivery = NotificationDelivery::where('store_id', $store->id)->first();
        $this->assertEquals('template', $delivery->channel);
        $this->assertEquals('sent', $delivery->status);
    }
}
