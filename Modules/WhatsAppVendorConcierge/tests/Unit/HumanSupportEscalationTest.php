<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Modules\WhatsAppVendorConcierge\app\Mail\VendorSupportEscalationMail;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationManager;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Tests\TestCase;

class HumanSupportEscalationTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_dispatches_support_email_and_alerts_vendor_on_handoff()
    {
        Mail::fake();

        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
            'display_name' => 'Support Vendor',
        ]);

        $session = \Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'human_handoff',
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'onboarding_session_id' => $session->id,
            'state' => 'ai_active',
        ]);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.' . uniqid(),
            'direction' => 'inbound',
            'type' => 'text',
            'content' => ['text' => 'I need help from support'],
            'status' => 'delivered',
        ]);

        $gateway = $this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())
            ->method('sendTextMessage')
            ->with(
                $this->equalTo($contact->phone_number),
                $this->stringContains("connected you with our support team")
            );

        $manager = app(ConversationManager::class);
        $manager->handleHumanHandoff($conversation, $contact, $message, $gateway);

        Mail::assertSent(VendorSupportEscalationMail::class, function ($mail) use ($conversation) {
            return $mail->conversation->id === $conversation->id;
        });

        $this->assertDatabaseHas('onboarding_events', [
            'contact_id' => $contact->id,
            'event_type' => 'human_handoff',
        ]);
    }
}
