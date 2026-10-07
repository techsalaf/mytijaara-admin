<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\DTOs\FlowSubmission;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use App\DTOs\VendorSelfRegistrationResult;
use App\Services\VendorSelfRegistrationService;

class FlowSubmissionProcessor
{
    public function process(FlowSubmission $in): string
    {
        if (! config('whatsapp-vendor-flow.enabled')) {
            return 'flow_disabled';
        }
        $session = null;
        try {
            $outcome = DB::transaction(function () use ($in, &$session) {
                $session = VendorFlowSession::where('token_hash', $in->tokenHash)->lockForUpdate()->first();
                if (! $session || ! hash_equals($session->sender, $in->sender) || $session->flow_id !== $in->flowId || $session->definition_version !== $in->definitionVersion || ($in->response['submitted'] ?? false) !== true) {
                    throw new \InvalidArgumentException('Flow binding mismatch.');
                }
                $host = OnboardingSession::find($session->onboarding_session_id);
                $contact = WhatsAppContact::find($session->contact_id);
                if ($host && in_array($host->status, ['abandoned', 'expired'], true)) {
                    throw new \InvalidArgumentException('Flow host session is no longer active.');
                }
                if (! $host || ! $contact || $host->contact_id !== $contact->id || \App\DTOs\VendorSelfRegistrationInput::normalizePhone($contact->whatsapp_id) !== $in->sender) {
                    throw new \InvalidArgumentException('Flow session mismatch.');
                }
                $receipt = DB::table('wa_vendor_flow_receipts')->where('message_id', $in->messageId)->first();
                if ($receipt && $receipt->flow_session_id != $session->id) {
                    throw new \InvalidArgumentException('Duplicate message binding mismatch.');
                }
                if ($receipt && $receipt->state === 'completed') {
                    return 'duplicate';
                }
                if ($session->consumed_at) {
                    throw new \InvalidArgumentException('Flow replay rejected.');
                }
                if ($session->expires_at->isPast()) {
                    throw new \InvalidArgumentException('Flow token expired.');
                }
                if (! $receipt) {
                    DB::table('wa_vendor_flow_receipts')->insert(['message_id' => $in->messageId, 'flow_session_id' => $session->id, 'state' => 'received', 'created_at' => now(), 'updated_at' => now()]);
                }
                $sm = app(FlowStateMachine::class);
                if (! $session->vendor_id) {
                    if (! in_array($session->state, ['flow_submitted', 'failed_recoverable'], true)) {
                        throw new \InvalidArgumentException('Flow draft is not ready.');
                    }
                    $sm->event($session, 'submission_received');
                    $sm->transition($session, 'registration_processing');
                    $input = app(FlowFieldMapper::class)->input($session, $session->draft ?? []);
                    if (! hash_equals($session->policy_manifest['presentation_hash'], $input->policyEvidence->presentationHash)) {
                        throw ValidationException::withMessages(['policy' => 'Review the current policies again.']);
                    }
                    $result = app(VendorSelfRegistrationService::class)->register($input);
                    $session->update(['vendor_id' => $result->vendor->id, 'store_id' => $result->store->id, 'error_code' => null]);
                    $host->update(['vendor_id' => $result->vendor->id, 'store_id' => $result->store->id, 'status' => 'submitted', 'completed_at' => now(), 'collected_data' => []]);
                    $contact->update(['vendor_id' => $result->vendor->id]);
                }

                return 'registered';
            }, 3);
            if ($outcome === 'duplicate') {
                return $outcome;
            }
            // Registration is committed before public media is promoted. Retry publication, never registration.
            $this->resumeRegistered($session->id);

            return 'completed';
        } catch (ValidationException $e) {
            $this->failure($session, 'correction_required', 'validation_correction');

            return 'correction_required';
        } catch (\InvalidArgumentException $e) {
            return 'invalid_submission';
        } catch (\Throwable $e) {
            $this->failure($session, 'failed_recoverable', 'registration_retry');
            throw new \RuntimeException('Flow registration temporarily unavailable.');
        }
    }

    public function resumeRegistered(int $sessionId): void
    {
        DB::transaction(function () use ($sessionId) {
            $s = VendorFlowSession::lockForUpdate()->findOrFail($sessionId);
            if (! $s->vendor_id || ! $s->store_id) {
                throw new \InvalidArgumentException('No committed registration to resume.');
            }
            if ($s->consumed_at) {
                DB::table('wa_vendor_flow_receipts')->where('flow_session_id', $s->id)->update(['state' => 'completed', 'updated_at' => now()]);

                return;
            }
            $result = new VendorSelfRegistrationResult(\App\Models\Vendor::findOrFail($s->vendor_id), \App\Models\Store::findOrFail($s->store_id), false);
            app(VendorSelfRegistrationService::class)->publishPreparedMedia($result);
            $sm = app(FlowStateMachine::class);
            if ($s->state === 'failed_recoverable') {
                $sm->transition($s, 'registration_processing');
            }
            $sm->transition($s, 'registration_completed');
            $sm->event($s, 'registration_completed');
            $sm->transition($s, 'credential_setup_pending');
            $s->update(['consumed_at' => now('UTC'), 'draft' => null, 'error_code' => null]);
            DB::table('wa_vendor_flow_media')->where('flow_session_id', $s->id)->where('state', 'staged')->update(['state' => 'promoted', 'updated_at' => now()]);
            DB::table('wa_vendor_flow_receipts')->where('flow_session_id', $s->id)->update(['state' => 'completed', 'updated_at' => now()]);
            WhatsAppConversation::where('onboarding_session_id', $s->onboarding_session_id)->update(['state' => 'onboarding_completed', 'vendor_id' => $s->vendor_id, 'current_step' => null, 'collected_data' => [], 'context' => []]);
            \Modules\WhatsAppVendorConcierge\app\Jobs\SendFlowRegistrationNotification::dispatch($s->id)->afterCommit();
        }, 3);
    }

    private function failure(?VendorFlowSession $candidate, string $state, string $code): void
    {
        if (! $candidate) {
            return;
        }
        DB::transaction(function () use ($candidate, $state, $code) {
            $s = VendorFlowSession::lockForUpdate()->find($candidate->id);
            if (! $s || $s->consumed_at) {
                return;
            }
            app(FlowStateMachine::class)->transition($s, $state);
            $s->update(['error_code' => $code]);
            if ($state === 'correction_required') {
                app(FlowStateMachine::class)->event($s, 'correction_required');
            }
        });
    }
}
