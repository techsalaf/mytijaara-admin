<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class ContinueCredentialOnboarding implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $sessionId, public string $step)
    {
        $connection = config('whatsapp-vendor-concierge.queue.connection', 'database');
        if ($connection === 'sync') {
            throw new \LogicException('Credential continuation requires an asynchronous queue.');
        }
        $this->onConnection($connection);
        $this->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_onboarding'));
    }

    public function handle(VendorOnboardingService $onboarding, WhatsAppGateway $gateway): void
    {
        $session = OnboardingSession::find($this->sessionId);
        if (!$session || $session->status !== 'started' || $session->current_step !== $this->step || $session->isExpired()) {
            return;
        }
        $conversation = WhatsAppConversation::where('onboarding_session_id', $session->id)
            ->where('contact_id', $session->contact_id)->latest()->first();
        if ($conversation && $conversation->state === 'human_handoff') {
            $support = app(\Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService::class);
            if ($case = $support->getActiveCase($session->contact)) {
                $support->resolveCase($case, 'Auto-resumed to onboarding upon password creation', $conversation);
            }
            $conversation->transitionTo('onboarding_active');
        }
        if ($conversation && $conversation->state === 'onboarding_active' && $session->contact && !$session->contact->is_blocked) {
            $onboarding->sendStepPrompt($conversation, $session->contact, $this->step, $gateway);
        }
    }
}
