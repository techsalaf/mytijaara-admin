<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;
use Illuminate\Support\Facades\DB;

class FlowStateMachine
{
    public const EDGES = [
        'flow_offered' => ['flow_opened', 'failed_recoverable', 'failed_terminal'],
        'flow_opened' => ['flow_draft', 'correction_required', 'failed_recoverable', 'failed_terminal'],
        'flow_draft' => ['flow_draft', 'media_processing', 'flow_submitted', 'correction_required', 'failed_recoverable', 'failed_terminal'],
        'media_processing' => ['flow_draft', 'failed_recoverable', 'correction_required', 'failed_terminal'],
        'flow_submitted' => ['flow_draft', 'registration_processing', 'correction_required', 'failed_recoverable', 'failed_terminal'],
        'registration_processing' => ['registration_completed', 'correction_required', 'failed_recoverable', 'failed_terminal'],
        'registration_completed' => ['credential_setup_pending'],
        'credential_setup_pending' => [],
        'correction_required' => ['flow_draft', 'media_processing', 'flow_submitted', 'failed_terminal'],
        'failed_recoverable' => ['flow_draft', 'media_processing', 'flow_submitted', 'registration_processing', 'failed_terminal'],
        'failed_terminal' => [],
    ];

    public function transition(VendorFlowSession $s, string $to): void
    {
        if (! in_array($to, self::EDGES[$s->state] ?? [], true)) {
            throw new \LogicException('Invalid Flow state transition.');
        }
        $s->state = $to;
        $s->save();
    }

    public function event(?VendorFlowSession $s, string $event, ?string $screen = null): void
    {
        if (! in_array($event, ['flow_offered', 'flow_opened', 'draft_progress', 'submission_received', 'correction_required', 'media_failure', 'registration_completed', 'credential_setup_completed', 'fallback_to_chat', 'expired'], true)) {
            throw new \InvalidArgumentException('Unknown funnel event.');
        }
        DB::table('wa_vendor_flow_events')->insert(['flow_session_id' => $s?->id, 'event' => $event, 'screen' => $screen, 'created_at' => now('UTC')]);
    }
}
