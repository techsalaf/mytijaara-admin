<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowOnboardingService;

class TestRecipient
{
    public function check(string $number): array
    {
        app(RuntimeSettings::class)->apply();
        $contact = WhatsAppContact::where('whatsapp_id', $number)->first();
        $conversation = $contact ? WhatsAppConversation::where('contact_id', $contact->id)->latest('id')->first() : null;
        $inbound = $conversation?->messages()->where('direction', 'inbound')->latest('created_at')->first();
        $vendor = DB::table('vendors')->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?", [$number])->exists();
        $phoneObservation = DB::table('wa_flow_control_operations')->where('action', 'health')->where('state', 'succeeded')->latest('updated_at')->first();
        $phone = $phoneObservation ? (json_decode($phoneObservation->result, true)['phone']['display_phone_number'] ?? '') : '';
        $sender = preg_replace('/[^0-9]/', '', $phone);
        $sync = DB::table('wa_vendor_flow_sync')->where('definition_version', config('whatsapp-vendor-flow.definition_version'))->first();
        $allowlisted = in_array($number, config('whatsapp-vendor-flow.test_phones', []), true);
        $timestamp = $inbound?->metadata['timestamp'] ?? null;
        $window = (bool) ($inbound && ctype_digit((string) $timestamp) && (int) $timestamp > now()->subHours(24)->timestamp && (int) $timestamp <= now()->addMinute()->timestamp);
        $senderKnown = $sender !== '' && app(Readiness::class)->summary()['observations']['health']['fresh'];
        $published = $sync && $sync->status === 'published' && $sync->published_flow_id === config('whatsapp-vendor-flow.flow_id') && config('whatsapp-vendor-flow.mode') === 'published';
        $eligible = $senderKnown && $number !== $sender && ! $vendor && $window && $allowlisted && $published && $contact && $contact->opted_in_at && ! $contact->is_blocked && $conversation && $conversation->state !== 'human_handoff' && app(FlowOnboardingService::class)->enabledFor($number);

        return ['recipient' => '••••'.substr($number, -4), 'sender' => $phone ?: 'Unknown; refresh health', 'sender_verified' => $senderKnown, 'same_as_sender' => $number === $sender, 'existing_vendor' => $vendor, 'window_open' => $window, 'allowlisted' => $allowlisted, 'published' => (bool) $published, 'flow_id' => config('whatsapp-vendor-flow.flow_id'), 'definition_version' => config('whatsapp-vendor-flow.definition_version'), 'dispatch_enabled' => (bool) config('whatsapp-vendor-flow.enabled'), 'blocked_contact' => ! $contact || $contact->is_blocked, 'human_handoff' => $conversation?->state === 'human_handoff', 'eligible' => (bool) $eligible, 'contact_id' => $contact?->id, 'conversation_id' => $conversation?->id];
    }

    public function send(string $number, int $admin): string
    {
        $lock = Cache::lock('wa-flow-test-'.hash('sha256', $number), 120);
        abort_unless($lock->get(), 409, 'A test send is already in progress.');
        try {
            $check = $this->check($number);
            abort_unless($check['eligible'], 422, 'Current preflight failed. Resolve each requirement before sending.');
            $conversation = WhatsAppConversation::findOrFail($check['conversation_id']);
            $session = OnboardingSession::whereKey($conversation->onboarding_session_id)->first();
            abort_unless($session && ! $session->vendor_id, 422, 'Start onboarding from the controlled recipient before dispatch.');
            abort_if(in_array($session->status, ['submitted', 'approved', 'rejected'], true), 409, 'This application is not a resumable draft.');
            $existing = DB::table('wa_vendor_flow_sessions')->where('onboarding_session_id', $session->id)->first();
            abort_if($existing && $existing->expires_at > now()->toDateTimeString() && $existing->state === 'flow_offered', 409, 'An offered Flow is already active. Inspect it before redispatching.');
            DB::transaction(function () use ($session) {
                $fresh = OnboardingSession::lockForUpdate()->findOrFail($session->id);
                abort_if($fresh->vendor_id, 409, 'Registration completed during preflight.');
                if (in_array($fresh->status, ['expired', 'abandoned'], true)) {
                    $fresh->update(['status' => 'started', 'expires_at' => now()->addDay()]);
                }
            });
            // Record intent before I/O. Ambiguous acceptance must be inspected, not automatically retried.
            app(Audit::class)->record($admin, 'test_send', (string) $session->id, 'attempted', ['recipient' => $check['recipient'], 'flow_id' => $check['flow_id']]);
            $offered = app(FlowOnboardingService::class)->offer($session, WhatsAppContact::findOrFail($check['contact_id']), $conversation);
            $flow = DB::table('wa_vendor_flow_sessions')->where('onboarding_session_id', $session->id)->first();
            $ok = $offered && $flow && $flow->state === 'flow_offered';
            app(Audit::class)->record($admin, 'test_send', (string) $session->id, $ok ? 'accepted' : 'failed', ['flow_session_id' => $flow?->id]);

            return $ok ? 'Meta accepted the Flow offer. Follow delivery receipts in the inbox; acceptance is not delivery.' : 'Flow offer failed. Inspect the session and inbox before retrying.';
        } finally {
            $lock->release();
        }
    }
}
