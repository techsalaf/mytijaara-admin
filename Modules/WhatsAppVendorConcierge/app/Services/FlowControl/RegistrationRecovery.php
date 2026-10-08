<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use App\DTOs\VendorSelfRegistrationInput;
use App\Services\VendorSecurityTokenService;
use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendFlowRegistrationNotification;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowSubmissionProcessor;

class RegistrationRecovery
{
    public function run(int $id, string $action): array
    {
        return DB::transaction(function () use ($id, $action) {
            $s = VendorFlowSession::lockForUpdate()->findOrFail($id);
            if (! $s->vendor_id || ! $s->store_id) {
                throw new Failure('Only an already committed registration can be recovered. No vendor is created by this action.');
            }
            if ($action === 'retry_processing') {
                if (! $s->consumed_at) {
                    app(FlowSubmissionProcessor::class)->resumeRegistered($id);
                }

                return ['flow_session_id' => $id, 'state' => $s->fresh()->state, 'message' => 'Committed media publication reconciled. Any notification is queued separately; no registration was recreated.'];
            }
            if ($action !== 'credential_resend' || ! $s->consumed_at || $s->state !== 'credential_setup_pending' || $s->credential_setup_completed_at) {
                throw new Failure('Credential resend requires a completed registration awaiting credential setup.');
            }
            $vendor = DB::table('vendors')->where('id', $s->vendor_id)->first(['id', 'status', 'phone']);
            $store = DB::table('stores')->where('id', $s->store_id)->first(['id', 'status', 'vendor_id']);
            if (! $vendor || ! $store || $vendor->status !== null || (int) $store->status !== 0 || (int) $store->vendor_id !== (int) $vendor->id || VendorSelfRegistrationInput::normalizePhone($vendor->phone) !== $s->sender) {
                throw new Failure('Pending account ownership or approval boundary changed.');
            }
            if (DB::table('vendor_security_tokens')->where('vendor_id', $s->vendor_id)->where('purpose', VendorSecurityTokenService::FLOW_SETUP)->whereNotNull('consumed_at')->exists()) {
                throw new Failure('Credential setup already completed. Use canonical authentication recovery.');
            }
            if (DB::table('wa_vendor_flow_events')->where('flow_session_id', $id)->where('event', 'credential_setup_completed')->exists()) {
                throw new Failure('Credentials are already configured. Use the canonical authentication recovery path.');
            }
            $contact = WhatsAppContact::findOrFail($s->contact_id);
            $c = WhatsAppConversation::where('onboarding_session_id', $s->onboarding_session_id)->first();
            $message = $c?->messages()->where('direction', 'inbound')->latest('created_at')->first();
            $timestamp = $message?->metadata['timestamp'] ?? null;
            if ($contact->is_blocked || ! $contact->opted_in_at || ! ctype_digit((string) $timestamp) || (int) $timestamp <= now()->subHours(24)->timestamp || (int) $timestamp > now()->addMinute()->timestamp) {
                throw new Failure('An opted-in, unblocked recipient and open service window are required.');
            }
            $recent = DB::table('wa_flow_control_audits')->where('action', 'credential_resend')->where('target', 'session:'.$id)->where('outcome', 'succeeded')->where('created_at', '>', now()->subMinutes(5))->exists();
            if ($recent) {
                throw new Failure('A credential resend was recently queued. Inspect delivery before retrying.');
            }
            $s->update(['notification_status' => 'pending']);
            SendFlowRegistrationNotification::dispatch($id)->afterCommit();

            return ['flow_session_id' => $id, 'message' => 'Purpose-bound credential setup notification queued. Sending a link does not approve or activate the vendor.'];
        });
    }
}
