<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\CredentialToken;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\ConversationManager;
use Modules\WhatsAppVendorConcierge\app\Services\CredentialTokenService;
use Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use PHPUnit\Framework\Attributes\Test;

class ConversationNavigationTest extends HardeningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Schema::create('modules', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('module_name');
            $table->string('module_type');
            $table->boolean('status')->default(true);
        });
        foreach (['categories', 'zones'] as $name) {
            \Illuminate\Support\Facades\Schema::create($name, function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id(); $table->string('name'); $table->boolean('status')->default(true);
                $table->unsignedBigInteger('parent_id')->default(0);
            });
        }
        \Illuminate\Support\Facades\Schema::create('subscription_packages', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id(); $table->string('package_name'); $table->string('module_type');
            $table->boolean('status')->default(true); $table->decimal('price'); $table->integer('validity'); $table->timestamps();
        });
    }

    private function receive($contact, array $body, WhatsAppGateway $gateway): void
    {
        $data = array_merge(['id' => 'wamid.' . bin2hex(random_bytes(8)), 'from' => $contact->whatsapp_id], $body);
        // Exercise all three privacy boundaries: controller, job, and message storage.
        $job = new ProcessIncomingWhatsAppMessage(InboundPrivacy::redact($data), []);
        $job->handle($gateway, app(VendorOnboardingService::class), app(ConversationManager::class));
    }

    private function gateway(): WhatsAppGateway
    {
        $gateway = \Mockery::mock(WhatsAppGateway::class);
        $gateway->shouldReceive('markAsRead')->with(\Mockery::type('string'), true)->andReturn(['success' => true]);
        return $gateway;
    }

    #[Test]
    public function restart_aliases_escape_password_stage_and_invalidate_old_links(): void
    {
        [$contact, $session, $conversation] = $this->application();
        $conversation->update(['collected_data' => ['stale' => true], 'context' => ['stale' => true]]);
        $gateway = $this->gateway();
        $gateway->shouldReceive('sendTextMessage')->andReturn([]);
        foreach (['Start', 'Reset', 'restart', 'start over', 'Create shop', 'I want to create a shop'] as $command) {
            $session->update(['current_step' => 'account_password']);
            $conversation->update(['current_step' => 'account_password']);
            $token = basename(app(CredentialTokenService::class)->issue($session));
            $this->receive($contact, ['type' => 'text', 'text' => ['body' => $command]], $gateway);
            $this->assertSame('abandoned', $session->fresh()->status, $command);
            $this->assertNull(app(CredentialTokenService::class)->resolve($token));
            $this->assertNotNull(CredentialToken::where('token_hash', hash('sha256', $token))->first()->revoked_at);
            $conversation->refresh();
            $this->assertSame('business_basics', $conversation->current_step);
            $this->assertSame([], $conversation->collected_data);
            $this->assertSame([], $conversation->context);
            $session = OnboardingSession::findOrFail($conversation->onboarding_session_id);
            $this->assertEmpty($session->collected_data);
        }
    }

    #[Test]
    public function greeting_offers_navigation_and_start_new_button_escapes_password_stage(): void
    {
        [$contact, $session, $conversation] = $this->application();
        $gateway = $this->gateway();
        $gateway->shouldReceive('sendButtonMessage')->once()->withArgs(function ($phone, $body, $buttons, $header) {
            return array_column($buttons, 'id') === ['resume_onboarding', 'start_fresh', 'talk_support'];
        })->andReturn([]);
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $this->receive($contact, ['type' => 'text', 'text' => ['body' => 'Hi']], $gateway);
        $this->assertSame('welcome', $conversation->fresh()->state);
        $this->assertSame('started', $session->fresh()->status);
        $this->receive($contact, ['type' => 'interactive', 'interactive' => ['button_reply' => ['id' => 'start_fresh', 'title' => 'DoNotPersist@2026']]], $gateway);
        $this->assertSame('business_basics', $conversation->fresh()->current_step);
        $this->assertStringNotContainsString('DoNotPersist', WhatsAppMessage::all()->toJson());
    }

    #[Test]
    public function continue_and_resend_link_work_without_accepting_passwords_in_chat(): void
    {
        [$contact, $session, $conversation] = $this->application();
        $gateway = $this->gateway();
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $gateway->shouldReceive('sendCtaUrlMessage')->times(3)->andReturn([]);
        foreach (['Continue', 'Resend Link', 'DoNotPersist@2026'] as $text) {
            $this->receive($contact, ['type' => 'text', 'text' => ['body' => $text]], $gateway);
            $this->assertSame($session->id, $conversation->fresh()->onboarding_session_id);
            $this->assertSame('account_password', $conversation->fresh()->current_step);
        }
        $this->assertStringNotContainsString('DoNotPersist', WhatsAppMessage::all()->toJson());
    }

    #[Test]
    public function restart_preserves_submitted_application_and_does_not_revive_abandoned_drafts(): void
    {
        [$contact, $session, $conversation] = $this->application();
        $session->update(['status' => 'submitted']);
        $gateway = $this->gateway();
        $gateway->shouldReceive('sendTextMessage')->once()->andReturn([]);
        $this->receive($contact, ['type' => 'text', 'text' => ['body' => 'Reset']], $gateway);
        $this->assertSame('submitted', $session->fresh()->status);
        $this->assertSame(1, OnboardingSession::count());
        $session->update(['status' => 'abandoned']);
        $this->assertNull(app(VendorOnboardingService::class)->resumeOnboarding($contact, $conversation));
    }

    #[Test]
    public function read_receipt_supports_typing_and_can_disable_it_for_human_handoff(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"success":true}'), new Response(200, [], '{"success":true}') ]));
        $stack->push(Middleware::history($history));
        $gateway = app(WhatsAppGateway::class);
        (new \ReflectionProperty($gateway, 'client'))->setValue($gateway, new Client(['handler' => $stack, 'base_uri' => 'https://graph.facebook.com/v21.0/']));
        $gateway->markAsRead('wamid.test', true);
        $gateway->markAsRead('wamid.human');
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.test', 'typing_indicator' => ['type' => 'text']], $payload);
        $this->assertSame('/v21.0/test-phone/messages', $history[0]['request']->getUri()->getPath());
        $this->assertArrayNotHasKey('typing_indicator', json_decode((string) $history[1]['request']->getBody(), true));
    }

    #[Test]
    public function first_time_menu_offers_shop_selling_information_and_support(): void
    {
        [$contact, , $conversation] = $this->application();
        $gateway = $this->gateway();
        $gateway->shouldReceive('sendButtonMessage')->once()->withArgs(function ($phone, $body, $buttons, $header) {
            return array_column($buttons, 'id') === ['open_shop', 'learn_selling', 'talk_support'];
        })->andReturn([]);
        app(ConversationManager::class)->showMainMenu($conversation, $contact, $gateway);
        $this->assertSame('support', \Modules\WhatsAppVendorConcierge\app\Services\ConversationCommands::action('I want to talk to support'));
    }
}
