<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Tests\TestCase;

class WhatsAppApiControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
    }

    /** @test */
    public function it_dispatches_message_via_api()
    {
        Queue::fake();

        $phone = '2348012345678';
        $response = $this->postJson('/api/v1/whatsapp/send', [
            'recipient_phone' => $phone,
            'type' => 'text',
            'content' => ['body' => 'Hello from API'],
        ]);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'queued']);

        Queue::assertPushed(SendWhatsAppMessage::class, function ($job) use ($phone) {
            return $job->to === $phone
                && $job->payload['body'] === 'Hello from API';
        });
    }

    /** @test */
    public function it_lists_conversations_and_history()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
            'display_name' => 'API Test User',
        ]);

        $conversation = WhatsAppConversation::create([
            'contact_id' => $contact->id,
            'state' => 'welcome',
            'last_activity_at' => now(),
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.' . uniqid(),
            'direction' => 'inbound',
            'type' => 'text',
            'content' => ['text' => 'Test message'],
            'status' => 'delivered',
        ]);

        $convResponse = $this->getJson('/api/v1/whatsapp/conversations');
        $convResponse->assertStatus(200);

        $historyResponse = $this->getJson('/api/v1/whatsapp/messages/' . $conversation->id);
        $historyResponse->assertStatus(200);
        $historyResponse->assertJsonStructure(['conversation', 'messages']);
    }

    /** @test */
    public function it_returns_onboarding_analytics()
    {
        $contact = WhatsAppContact::create([
            'whatsapp_id' => 'wa_' . uniqid(),
            'phone_number' => '234' . rand(8000000000, 8099999999),
        ]);

        OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'started',
            'current_step' => 'category_selection',
            'started_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->getJson('/api/v1/whatsapp/onboarding/analytics');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'metrics' => [
                'total_started',
                'in_progress',
                'submitted',
                'approved',
                'abandoned',
                'conversion_rate_percent',
            ],
            'step_distribution_in_progress',
            'events_summary',
        ]);
    }

    /** @test */
    public function it_tests_webhook_signature_calculation()
    {
        config(['whatsapp-vendor-concierge.api.app_secret' => 'test_secret_123']);

        $payload = json_encode(['test' => true]);
        $expectedHash = hash_hmac('sha256', $payload, 'test_secret_123');

        $response = $this->withHeaders([
            'X-Hub-Signature-256' => 'sha256=' . $expectedHash,
        ])->postJson('/api/v1/whatsapp/test-signature', ['test' => true]);

        $response->assertStatus(200);
        $response->assertJson([
            'is_valid' => true,
            'app_secret_configured' => true,
        ]);
    }
}
