<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Admin;
use App\Models\AdminRole;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use PHPUnit\Framework\Attributes\Test;

class OperationsAuthorizationTest extends HardeningTestCase
{
    private function login(array $permissions = ['store', 'contact_messages']): void
    {
        $admin = new Admin(['role_id' => 2, 'is_logged_in' => true, 'login_remember_token' => 'test-session']);
        $admin->id = 99;
        $admin->setRelation('role', new AdminRole(['modules' => json_encode($permissions)]));
        $this->actingAs($admin, 'admin')->withSession(['login_remember_token' => 'test-session']);
    }

    #[Test]
    public function every_operations_route_rejects_anonymous_access(): void
    {
        foreach (['conversations', 'messages/1', 'onboarding/sessions', 'onboarding/sessions/1', 'onboarding/analytics'] as $path) {
            $this->getJson('/api/v1/whatsapp/'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/whatsapp/send', [])->assertUnauthorized();
        $this->postJson('/api/v1/whatsapp/test-signature', [])->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function unrelated_admin_role_cannot_read_or_send(): void
    {
        $this->login(['store']);
        $this->getJson('/api/v1/whatsapp/conversations')->assertForbidden();
        $this->postJson('/api/v1/whatsapp/send', [])->assertForbidden();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function authorized_admin_can_read_events_by_canonical_foreign_key_and_queue_messages(): void
    {
        $this->login();
        [$contact, $session, $conversation] = $this->application();
        OnboardingEvent::log($session->id, $contact->id, 'onboarding_started');
        $this->getJson('/api/v1/whatsapp/onboarding/sessions/'.$session->id)
            ->assertOk()->assertJsonPath('events.0.onboarding_session_id', $session->id);
        $this->postJson('/api/v1/whatsapp/send', [
            'recipient_phone' => $contact->phone_number, 'type' => 'text', 'content' => ['body' => 'Hello'],
            'conversation_id' => $conversation->id,
        ])->assertStatus(202);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
    }

    #[Test]
    public function expired_admin_session_is_rejected(): void
    {
        $this->login();
        $this->withSession(['login_remember_token' => 'revoked'])->getJson('/api/v1/whatsapp/conversations')->assertUnauthorized();
    }

    #[Test]
    public function debug_does_not_sign_payloads_and_is_absent_in_production(): void
    {
        $this->login();
        $this->postJson('/api/v1/whatsapp/test-signature', ['test' => true])
            ->assertOk()->assertJsonMissingPath('expected_signature');
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'csrf-test'])->postJson('/api/v1/whatsapp/test-signature', ['test' => true, '_token' => 'csrf-test'])->assertNotFound();
    }
}
