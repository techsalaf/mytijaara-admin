<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\tests\Hardening\ApplicationFixtureTestCase;

class AdminApprovalNotificationTest extends ApplicationFixtureTestCase
{

    /** @test */
    public function it_dispatches_whatsapp_message_when_vendor_is_approved()
    {
        Queue::fake();

        $phone = '234' . rand(8000000000, 8099999999);
        $vendor = Vendor::create([
            'f_name' => 'Approval',
            'l_name' => 'Test',
            'email' => 'approval_' . uniqid() . '@test.com',
            'phone' => $phone,
            'password' => bcrypt('password123'),
            'status' => null,
        ]);

        $store = Store::forceCreate(['name' => 'Applicant shop', 'phone' => $phone,
            'vendor_id' => $vendor->id, 'status' => 0]);
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => $phone,
            'vendor_id' => $vendor->id,
            'display_name' => 'Approval Test',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'state' => 'onboarding_completed',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'status' => 'submitted',
            'current_step' => 'review_submit',
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        // Simulate admin approving vendor
        $vendor->status = 1;
        $vendor->save();

        Queue::assertPushed(SendVendorStatusNotification::class, fn ($job) =>
            $job->storeId === $store->id && $job->status === 'approved');
        Queue::assertPushed(SendVendorStatusNotification::class, 1);

        $conversation->refresh();
        $this->assertEquals('ai_active', $conversation->state);

        $session->refresh();
        $this->assertEquals('approved', $session->status);
    }

    /** @test */
    public function it_dispatches_whatsapp_message_when_vendor_is_denied()
    {
        Queue::fake();

        $phone = '234' . rand(8000000000, 8099999999);
        $vendor = Vendor::create([
            'f_name' => 'Denial',
            'l_name' => 'Test',
            'email' => 'denial_' . uniqid() . '@test.com',
            'phone' => $phone,
            'password' => bcrypt('password123'),
            'status' => null,
        ]);

        $store = Store::forceCreate(['name' => 'Applicant shop', 'phone' => $phone,
            'vendor_id' => $vendor->id, 'status' => 0]);
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => $phone,
            'vendor_id' => $vendor->id,
            'display_name' => 'Denial Test',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor->id,
            'state' => 'onboarding_completed',
        ]);

        // Simulate admin rejecting with note
        $vendor->status = 0;
        $vendor->rejection_note = 'Invalid CAC document provided.';
        $vendor->save();

        Queue::assertPushed(SendVendorStatusNotification::class, fn ($job) =>
            $job->storeId === $store->id && $job->status === 'denied'
                && $job->rejectionNote === 'Invalid CAC document provided.');
        Queue::assertPushed(SendVendorStatusNotification::class, 1);

        $conversation->refresh();
        $this->assertEquals('welcome', $conversation->state);
    }
}
