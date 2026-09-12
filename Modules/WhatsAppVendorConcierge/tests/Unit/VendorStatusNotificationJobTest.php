<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Tests\TestCase;

class VendorStatusNotificationJobTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_sends_approval_notification_to_vendor_whatsapp()
    {
        $phone = '234' . rand(8000000000, 8099999999);
        $vendor = Vendor::create([
            'f_name' => 'Ahmad',
            'l_name' => 'Ibrahim',
            'email' => 'ahmad_' . uniqid() . '@test.com',
            'phone' => $phone,
            'password' => bcrypt('StrongPass@2026'),
            'status' => 1,
        ]);

        $store = Store::create([
            'name' => 'Ahmad Supermart',
            'phone' => $phone,
            'email' => $vendor->email,
            'vendor_id' => $vendor->id,
            'zone_id' => 1,
            'module_id' => 1,
            'status' => 1,
        ]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_ahmad_' . uniqid(),
            'phone_number' => $phone,
            'vendor_id' => $vendor->id,
            'display_name' => 'Ahmad Ibrahim',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'state' => 'onboarding_completed',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                $phone,
                $this->callback(function ($msg) {
                    return str_contains($msg, 'approved') && str_contains($msg, 'Ahmad Supermart');
                })
            );

        $job = new SendVendorStatusNotification($store->id, SendVendorStatusNotification::TYPE_APPROVED);
        $job->handle($gateway);

        $conversation->refresh();
        $this->assertEquals('ai_active', $conversation->state);

        $contact->refresh();
        $this->assertTrue($contact->isVendor());
    }

    /** @test */
    public function it_sends_denial_notification_with_rejection_note()
    {
        $phone = '234' . rand(8000000000, 8099999999);
        $vendor = Vendor::create([
            'f_name' => 'Fatima',
            'l_name' => 'Aliyu',
            'email' => 'fatima_' . uniqid() . '@test.com',
            'phone' => $phone,
            'password' => bcrypt('StrongPass@2026'),
            'status' => 0,
        ]);

        $store = Store::create([
            'name' => 'Fatima Boutique',
            'phone' => $phone,
            'email' => $vendor->email,
            'vendor_id' => $vendor->id,
            'zone_id' => 1,
            'module_id' => 1,
            'status' => 0,
        ]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_fatima_' . uniqid(),
            'phone_number' => $phone,
            'vendor_id' => $vendor->id,
            'display_name' => 'Fatima Aliyu',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'state' => 'onboarding_completed',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                $phone,
                $this->callback(function ($msg) {
                    return str_contains($msg, 'could not be approved') && str_contains($msg, 'CAC certificate image is blurry');
                })
            );

        $job = new SendVendorStatusNotification(
            $store->id,
            SendVendorStatusNotification::TYPE_DENIED,
            'CAC certificate image is blurry. Please provide a clear scan.'
        );
        $job->handle($gateway);
    }

    /** @test */
    public function it_sends_suspension_and_unsuspension_notifications()
    {
        $phone = '234' . rand(8000000000, 8099999999);
        $vendor = Vendor::create([
            'f_name' => 'Sani',
            'l_name' => 'Bello',
            'email' => 'sani_' . uniqid() . '@test.com',
            'phone' => $phone,
            'password' => bcrypt('StrongPass@2026'),
            'status' => 1,
        ]);

        $store = Store::create([
            'name' => 'Sani Electronics',
            'phone' => $phone,
            'email' => $vendor->email,
            'vendor_id' => $vendor->id,
            'zone_id' => 1,
            'module_id' => 1,
            'status' => 1,
        ]);

        WhatsAppContact::create([
            'whatsapp_id' => 'wa_sani_' . uniqid(),
            'phone_number' => $phone,
            'vendor_id' => $vendor->id,
            'display_name' => 'Sani Bello',
        ]);

        $messagesSent = [];
        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->exactly(2))
            ->method('sendTextMessage')
            ->willReturnCallback(function ($to, $msg) use (&$messagesSent) {
                $messagesSent[] = ['to' => $to, 'msg' => $msg];
                return ['message_id' => 'test_' . uniqid()];
            });

        // Suspension
        $suspendJob = new SendVendorStatusNotification($store->id, SendVendorStatusNotification::TYPE_SUSPENDED);
        $suspendJob->handle($gateway);

        // Reactivation
        $unsuspendJob = new SendVendorStatusNotification($store->id, SendVendorStatusNotification::TYPE_UNSUSPENDED);
        $unsuspendJob->handle($gateway);

        $this->assertCount(2, $messagesSent);
        $this->assertEquals($phone, $messagesSent[0]['to']);
        $this->assertStringContainsString('temporarily suspended', $messagesSent[0]['msg']);
        $this->assertEquals($phone, $messagesSent[1]['to']);
        $this->assertStringContainsString('re-activated', $messagesSent[1]['msg']);
    }
}
