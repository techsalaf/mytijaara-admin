<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Jobs\ContinueCredentialOnboarding;
use Modules\WhatsAppVendorConcierge\app\Models\CredentialToken;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\CredentialTokenService;
use Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy;
use PHPUnit\Framework\Attributes\Test;

class CredentialSecurityTest extends HardeningTestCase
{
    #[Test]
    public function tokens_are_hashed_regenerated_and_atomically_single_use(): void
    {
        [, $session, $conversation] = $this->application();
        $service = app(CredentialTokenService::class);
        $first = basename($service->issue($session));
        $second = basename($service->issue($session));
        $this->assertNull($service->resolve($first));
        $this->assertNotNull(CredentialToken::where('token_hash', hash('sha256', $first))->first()->revoked_at);
        $this->assertNotNull($service->resolve($second));
        $this->assertArrayNotHasKey('_pwd_token_hash', $session->fresh()->collected_data);
        $this->assertStringNotContainsString($second, CredentialToken::first()->toJson());
        $hash = Hash::make('StrongPass@2026');
        $this->assertTrue($service->consume($second, $hash));
        $this->assertFalse($service->consume($second, Hash::make('OtherPass@2026')));
        $this->assertSame($hash, $session->fresh()->collected_data['password_hash']);
        $this->assertSame('store_branding', $conversation->fresh()->current_step);
        Queue::assertPushed(ContinueCredentialOnboarding::class, 1);
        $this->assertStringNotContainsString($hash, $session->fresh()->toJson());
    }

    #[Test]
    public function expired_invalid_revoked_and_abandoned_tokens_are_rejected(): void
    {
        [, $session] = $this->application();
        $service = app(CredentialTokenService::class);
        $token = basename($service->issue($session));
        $this->assertNull($service->resolve('invalid'));
        $this->travel(16)->minutes();
        $this->assertNull($service->resolve($token));
        $this->assertFalse($service->consume($token, 'hash'));
        $this->travelBack();
        $session->update(['status' => 'abandoned']);
        $this->assertNull($service->resolve($token));
        $this->assertFalse($service->consume($token, 'hash'));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function url_uses_configured_https_origin_despite_request_host(): void
    {
        [, $session] = $this->application();
        \Illuminate\Support\Facades\URL::forceRootUrl('http://attacker.test');
        $url = app(CredentialTokenService::class)->issue($session);
        $this->assertStringStartsWith('https://dashboard.mytijaara.test/whatsapp/onboarding/password/', $url);
        config(['app.url' => 'http://dashboard.mytijaara.test']);
        $this->expectException(\LogicException::class);
        app(CredentialTokenService::class)->issue($session);
    }

    #[Test]
    public function password_validation_and_successful_https_continuation(): void
    {
        [, $session] = $this->application();
        $token = basename(app(CredentialTokenService::class)->issue($session));
        $path = '/whatsapp/onboarding/password/' . $token;
        $this->get($path)->assertOk()->assertSee('Ibadan Store')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->postJson($path, ['password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable();
        $this->postJson($path, ['password' => 'StrongPass@2026', 'password_confirmation' => 'different'])->assertUnprocessable();
        $this->assertSame(2, (int) CredentialToken::first()->attempt_count);
        $this->postJson($path, ['password' => 'StrongPass@2026', 'password_confirmation' => 'StrongPass@2026'])->assertOk();
        $this->assertTrue(Hash::check('StrongPass@2026', $session->fresh()->collected_data['password_hash']));
        $this->postJson($path, ['password' => 'StrongPass@2026', 'password_confirmation' => 'StrongPass@2026'])->assertGone();
        Queue::assertPushed(ContinueCredentialOnboarding::class, 1);
    }

    #[Test]
    public function failed_password_attempts_are_atomically_limited_and_revoke_the_link(): void
    {
        config(['whatsapp-vendor-concierge.security.credential_token_max_attempts' => 3]);
        [, $session] = $this->application();
        $token = basename(app(CredentialTokenService::class)->issue($session));
        $path = '/whatsapp/onboarding/password/' . $token;

        $this->postJson($path, ['password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable();
        $this->postJson($path, ['password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable();
        $this->assertSame(2, CredentialToken::first()->attempt_count);

        $this->postJson($path, ['password' => 'weak', 'password_confirmation' => 'weak'])->assertUnprocessable();
        $record = CredentialToken::first()->fresh();
        $this->assertSame(3, $record->attempt_count);
        $this->assertNotNull($record->revoked_at);
        $this->assertNull(app(CredentialTokenService::class)->resolve($token));
        $this->assertFalse(app(CredentialTokenService::class)->consume($token, Hash::make('StrongPass@2026')));
    }

    #[Test]
    public function redaction_removes_password_from_message_raw_text_metadata_and_serialized_job(): void
    {
        [$contact, , $conversation] = $this->application();
        $data = ['id' => 'wamid.password', 'from' => $contact->whatsapp_id, 'type' => 'text', 'text' => ['body' => 'DoNotPersist@2026']];
        $meta = ['messages' => [$data]];
        $message = WhatsAppMessage::logInbound($conversation->id, $data, $meta);
        $this->assertStringNotContainsString('DoNotPersist', $message->toJson());
        $job = new \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage(InboundPrivacy::redact($data), []);
        $this->assertStringNotContainsString('DoNotPersist', serialize($job));
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);
    }

    #[Test]
    public function credential_routes_enforce_throttling(): void
    {
        $path = '/whatsapp/onboarding/password/invalid';
        for ($i = 0; $i < 20; $i++) {
            $this->get($path)->assertOk();
        }
        $this->get($path)->assertStatus(429);
    }

    #[Test]
    public function csrf_remains_enabled_for_password_posts(): void
    {
        // Laravel skips CSRF in tests by default; explicitly enable the real check.
        $this->app->bind(\App\Http\Middleware\VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        });
        $this->postJson('/whatsapp/onboarding/password/invalid', [])->assertStatus(419);
    }
}
