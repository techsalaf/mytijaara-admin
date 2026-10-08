<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\DTOs\VendorSelfRegistrationInput;
use App\Services\RegistrationPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\FlowControl\RuntimeSettings;

class FlowOnboardingService
{
    public function enabledFor(string $phone): bool
    {
        app(RuntimeSettings::class)->apply();
        if (! config('whatsapp-vendor-flow.enabled') || ! in_array(config('whatsapp-vendor-flow.mode'), ['draft', 'published'], true) || ! preg_match('/^[0-9]+$/D', (string) config('whatsapp-vendor-flow.flow_id'))) {
            return false;
        }
        if (! app()->environment(['local', 'testing']) && (! config('whatsapp-vendor-flow.private_root_configured') || ! config('filesystems.disks.registration_private.configured'))) {
            return false;
        }
        if (! app()->environment(['local', 'testing'])) {
            try {
                app(FlowStorageInvariant::class)->assertSafe();
            } catch (\Throwable) {
                return false;
            }
            if (! Schema::hasTable('wa_vendor_flow_sync')) {
                return false;
            }
            $sync = DB::table('wa_vendor_flow_sync')->where('definition_version', config('whatsapp-vendor-flow.definition_version'))->first();
            if (! $sync || $sync->asset_hash !== hash_file('sha256', app(FlowDefinitionValidator::class)->path()) || $sync->status !== 'published' || $sync->published_flow_id !== config('whatsapp-vendor-flow.flow_id')) {
                return false;
            }
        }
        $allowed = config('whatsapp-vendor-flow.test_phones', []);
        if ($allowed) {
            return in_array($phone, array_map([VendorSelfRegistrationInput::class, 'normalizePhone'], $allowed), true);
        }
        if (config('whatsapp-vendor-flow.mode') === 'draft') {
            return false;
        }

        return hexdec(substr(hash('sha256', $phone), 0, 6)) % 100 < max(0, min(100, (int) config('whatsapp-vendor-flow.rollout_percent', 0)));
    }

    public function offer(OnboardingSession $session, WhatsAppContact $contact, WhatsAppConversation $conversation): bool
    {
        $sender = VendorSelfRegistrationInput::normalizePhone($contact->whatsapp_id);
        if (! $this->enabledFor($sender)) {
            return false;
        }
        $flow = null;
        try {
            $registered = VendorFlowSession::where('onboarding_session_id', $session->id)->whereNotNull('vendor_id')->first();
            if ($registered) {
                try {
                    app(FlowSubmissionProcessor::class)->resumeRegistered($registered->id);
                } catch (\Throwable $error) {
                    app(WhatsAppGateway::class)->sendTextMessage($sender, 'Your application was received. Media processing is still pending; please retry shortly.');
                }

                return true;
            }
            $locale = str_replace('_', '-', app()->getLocale());
            if ($locale !== 'en') {
                throw new \RuntimeException('This definition has no reviewed translation for this locale.');
            }
            $manifest = app(RegistrationPolicyService::class)->manifest($locale);
            app(FlowDefinitionValidator::class)->validate();
            app(FlowOptions::class)->modules();
            $raw = bin2hex(random_bytes(32));
            $flow = DB::transaction(function () use ($session, $contact, $sender, $locale, $manifest, $raw) {
                app(RuntimeSettings::class)->lock();
                if (! $this->enabledFor($sender)) {
                    throw new \RuntimeException('Flow dispatch changed before session creation.');
                }
                OnboardingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                $existing = VendorFlowSession::where('onboarding_session_id', $session->id)->first();
                if ($existing && ! $existing->consumed_at) {
                    if ($existing->vendor_id) {
                        throw new \RuntimeException('Committed registration requires publication recovery.');
                    }
                    $draft = array_diff_key($existing->draft ?? [], array_flip(FlowDataExchangeService::FIELDS['REVIEW']));
                    $existing->update(['draft' => $draft, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(config('whatsapp-vendor-flow.session_minutes', 60)), 'state' => 'flow_offered', 'policy_manifest' => $manifest, 'flow_id' => config('whatsapp-vendor-flow.flow_id'), 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'locale' => $locale]);

                    return $existing;
                }

                return VendorFlowSession::create(['onboarding_session_id' => $session->id, 'contact_id' => $contact->id, 'token_hash' => hash('sha256', $raw), 'sender' => $sender,
                    'flow_id' => config('whatsapp-vendor-flow.flow_id'), 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'locale' => $locale, 'state' => 'flow_offered',
                    'draft' => [], 'policy_manifest' => $manifest, 'expires_at' => now()->addMinutes(config('whatsapp-vendor-flow.session_minutes', 60))]);
            });
            $response = app(FlowMetaClient::class)->offer($sender, $flow->flow_id, $raw);
            $messageId = $response['messages'][0]['id'] ?? null;
            if (! is_string($messageId) || $messageId === '') {
                throw new \RuntimeException('Meta did not confirm the Flow offer.');
            }
            WhatsAppMessage::logOutbound($conversation->id, ['type' => 'interactive', 'interactive' => ['type' => 'flow', 'body' => ['text' => 'Registration form offered.']]], ['messages' => [['id' => $messageId]]]);
            $conversation->update(['current_step' => 'flow_active']);
            $session->update(['current_step' => 'flow_active']);
            app(FlowStateMachine::class)->event($flow, 'flow_offered');

            return true;
        } catch (\Throwable $e) {
            if ($flow) {
                $flow->update(['state' => 'failed_recoverable', 'error_code' => 'flow_offer_failed']);
            }
            if (config('whatsapp-vendor-flow.fallback_to_chat')) {
                app(FlowStateMachine::class)->event($flow, 'fallback_to_chat');

                return false;
            }
            app(WhatsAppGateway::class)->sendTextMessage($sender, 'The registration form is temporarily unavailable. Please try again later.');

            return true;
        }
    }
}
