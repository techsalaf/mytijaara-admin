<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Services\FlowStateMachine;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;

class ApplicationRecovery
{
    public function perform(int $id, string $action, int $admin): void
    {
        DB::transaction(function () use ($id, $action, $admin) {
            $s = VendorFlowSession::lockForUpdate()->findOrFail($id);
            $before = $s->state;
            if ($action === 'expire') {
                abort_if($s->vendor_id || $s->consumed_at || $s->expires_at->isFuture(), 409, 'Only abandoned, expired, unregistered drafts can be expired.');
                if ($s->state !== 'failed_terminal') {
                    foreach (DB::table('wa_vendor_flow_media')->where('flow_session_id', $id)->whereIn('state', ['staged', 'superseded'])->get() as $m) {
                        abort_unless(preg_match('~\Asessions/'.preg_quote((string) $id, '~').'/[a-f0-9]+\.(jpg|png|pdf)\z~D', $m->path), 409, 'Media ownership mismatch.');
                        if (Storage::disk('vendor_flow_private')->exists($m->path) && ! Storage::disk('vendor_flow_private')->delete($m->path)) {
                            throw new Failure('Private draft media cleanup failed. Retry after inspecting storage.');
                        }
                        DB::table('wa_vendor_flow_media')->where('id', $m->id)->update(['state' => 'cleaned', 'updated_at' => now()]);
                    }
                    app(FlowStateMachine::class)->event($s, 'expired');
                    $s->update(['state' => 'failed_terminal', 'draft' => null]);
                }
            } elseif ($action === 'assign_human') {
                $c = WhatsAppConversation::where('onboarding_session_id', $s->onboarding_session_id)->lockForUpdate()->firstOrFail();
                $cases = app(SupportCaseService::class);
                if (! $cases->getActiveCase($c->contact)) {
                    $cases->createCase($c->contact, 'Flow application needs administrative help', 'general', 'medium', null, $c);
                }
                $c->transitionTo('human_handoff');
            } elseif ($action !== 'review') {
                throw new Failure('Unsupported recovery.');
            }
            app(Audit::class)->record($admin, 'application_'.$action, (string) $id, 'succeeded', ['before_state' => $before, 'after_state' => $s->state]);
        });
    }
}
