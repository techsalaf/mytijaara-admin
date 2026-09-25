<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\Operations;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeHealthCheck;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Services\VendorOnboardingService;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;

class ConciergeRecoveryService
{
    public function __construct(
        protected ConciergeDiagnosticService $diagnosticService,
        protected WhatsAppGateway $gateway,
        protected VendorOnboardingService $onboardingService,
        protected SupportCaseService $supportCaseService
    ) {}

    /**
     * Release a conversation stuck in human_handoff back to its appropriate active state.
     */
    public function releaseStaleHandoff(
        WhatsAppConversation $conversation,
        bool $dryRun = false,
        string $actor = 'admin',
        ?int $adminId = null,
        ?string $reason = null
    ): array {
        $correlationId = 'REC-' . strtoupper(Str::random(10));
        $contact = $conversation->contact;
        $previousState = $conversation->state;

        $targetState = ($conversation->onboarding_session_id && !$conversation->vendor_id)
            ? 'onboarding_active'
            : 'ai_active';

        $preview = [
            'conversation_id' => $conversation->id,
            'phone' => $contact->phone_number,
            'action' => 'release_stale_handoff',
            'previous_state' => $previousState,
            'proposed_state' => $targetState,
            'is_eligible' => ($previousState === 'human_handoff'),
            'dry_run' => $dryRun,
            'correlation_id' => $correlationId,
        ];

        if (!$preview['is_eligible']) {
            $preview['status'] = 'skipped';
            $preview['reason'] = 'Conversation is not in human_handoff mode.';
            return $preview;
        }

        if ($dryRun) {
            $preview['status'] = 'dry_run_passed';
            $preview['message'] = "Would release conversation from '{$previousState}' to '{$targetState}'.";
            return $preview;
        }

        try {
            $conversation->transitionTo($targetState);

            // Resolve any open support case
            if ($activeCase = $this->supportCaseService->getActiveCase($contact)) {
                $this->supportCaseService->resolveCase($activeCase, $reason ?? "Automatically released back to {$targetState} via Operations Centre");
            }

            // Audit log
            ConciergeRecoveryAudit::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'action' => 'release_stale_handoff',
                'initiated_by' => $actor,
                'admin_id' => $adminId,
                'previous_state' => $previousState,
                'proposed_state' => $targetState,
                'previous_step' => $conversation->current_step,
                'is_dry_run' => false,
                'status' => 'success',
                'reason' => $reason ?? 'Released stale human handoff back to automation',
                'details' => ['resolved_case_id' => $activeCase?->id],
                'correlation_id' => $correlationId,
            ]);

            $preview['status'] = 'success';
            $preview['message'] = "Successfully released to {$targetState}.";
            return $preview;

        } catch (\Throwable $e) {
            Log::error('OperationsCenter releaseStaleHandoff failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            ConciergeRecoveryAudit::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'action' => 'release_stale_handoff',
                'initiated_by' => $actor,
                'admin_id' => $adminId,
                'previous_state' => $previousState,
                'proposed_state' => $targetState,
                'previous_step' => $conversation->current_step,
                'is_dry_run' => false,
                'status' => 'failed',
                'reason' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            $preview['status'] = 'failed';
            $preview['error'] = $e->getMessage();
            return $preview;
        }
    }

    /**
     * Safely re-nudge an onboarding conversation by resending its current step prompt.
     */
    public function renudgeCurrentStep(
        WhatsAppConversation $conversation,
        bool $dryRun = false,
        string $actor = 'admin',
        ?int $adminId = null,
        ?string $reason = null
    ): array {
        $correlationId = 'NUDGE-' . strtoupper(Str::random(10));
        $contact = $conversation->contact;
        $step = $conversation->current_step;

        $diag = $this->diagnosticService->diagnoseConversation($conversation);

        $preview = [
            'conversation_id' => $conversation->id,
            'phone' => $contact->phone_number,
            'action' => 'renudge_current_step',
            'step' => $step,
            'service_window_open' => $diag['service_window_open'],
            'can_nudge' => $diag['can_nudge'],
            'dry_run' => $dryRun,
            'correlation_id' => $correlationId,
        ];

        if (empty($step)) {
            $preview['status'] = 'skipped';
            $preview['reason'] = 'Conversation has no active onboarding step to prompt.';
            return $preview;
        }

        if (!$diag['service_window_open']) {
            $preview['status'] = 'excluded';
            $preview['reason'] = 'WhatsApp 24-hour service window has expired. Must use an approved template message.';
            return $preview;
        }

        if (!$diag['can_nudge'] && $actor === 'system_automated') {
            $preview['status'] = 'cooldown_active';
            $preview['reason'] = "Cooldown active (last nudge {$diag['minutes_since_last_nudge']}m ago, total {$diag['nudges_sent_count']}/3).";
            return $preview;
        }

        if ($dryRun) {
            $preview['status'] = 'dry_run_passed';
            $preview['message'] = "Would send prompt for step '{$step}' to {$contact->phone_number}.";
            return $preview;
        }

        try {
            // Re-send the prompt using canonical service
            $this->onboardingService->sendStepPrompt($conversation, $contact, $step, $this->gateway);

            // Audit
            ConciergeRecoveryAudit::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'action' => 'renudge_current_step',
                'initiated_by' => $actor,
                'admin_id' => $adminId,
                'previous_state' => $conversation->state,
                'proposed_state' => $conversation->state,
                'previous_step' => $step,
                'is_dry_run' => false,
                'status' => 'success',
                'reason' => $reason ?? "Re-nudged user with step '{$step}' prompt",
                'details' => ['step' => $step, 'actor' => $actor],
                'correlation_id' => $correlationId,
            ]);

            $preview['status'] = 'success';
            $preview['message'] = "Prompt for '{$step}' successfully resent.";
            return $preview;

        } catch (\Throwable $e) {
            Log::error('OperationsCenter renudgeCurrentStep failed', [
                'conversation_id' => $conversation->id,
                'step' => $step,
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            ConciergeRecoveryAudit::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'action' => 'renudge_current_step',
                'initiated_by' => $actor,
                'admin_id' => $adminId,
                'previous_state' => $conversation->state,
                'proposed_state' => $conversation->state,
                'previous_step' => $step,
                'is_dry_run' => false,
                'status' => 'failed',
                'reason' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            $preview['status'] = 'failed';
            $preview['error'] = $e->getMessage();
            return $preview;
        }
    }

    /**
     * Reprocess the last unhandled inbound message for a conversation.
     */
    public function reprocessLastInbound(
        WhatsAppConversation $conversation,
        bool $dryRun = false,
        string $actor = 'admin',
        ?int $adminId = null
    ): array {
        $correlationId = 'REP-' . strtoupper(Str::random(10));
        $contact = $conversation->contact;

        $lastInbound = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->latest('id')
            ->first();

        if (!$lastInbound) {
            return [
                'status' => 'skipped',
                'reason' => 'No inbound message exists for this conversation.',
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'dry_run_passed',
                'message' => "Would reprocess message #{$lastInbound->id}: '" . Str::limit($lastInbound->raw_text ?? '', 30) . "'",
                'correlation_id' => $correlationId,
            ];
        }

        try {
            $rawPayload = [
                'id' => $lastInbound->whatsapp_message_id ?? ('manual_' . Str::random(16)),
                'from' => $contact->phone_number,
                'timestamp' => time(),
                'type' => $lastInbound->type ?? 'text',
                'text' => ['body' => $lastInbound->raw_text],
            ];

            ProcessIncomingWhatsAppMessage::dispatchSync($rawPayload, [
                'metadata' => ['phone_number_id' => config('whatsapp-vendor-concierge.phone_number_id')],
                'contacts' => [['profile' => ['name' => $contact->name ?? 'Vendor'], 'wa_id' => $contact->phone_number]],
            ]);

            ConciergeRecoveryAudit::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'action' => 'reprocess_inbound',
                'initiated_by' => $actor,
                'admin_id' => $adminId,
                'previous_state' => $conversation->state,
                'proposed_state' => $conversation->state,
                'previous_step' => $conversation->current_step,
                'is_dry_run' => false,
                'status' => 'success',
                'reason' => "Reprocessed inbound message #{$lastInbound->id}",
                'correlation_id' => $correlationId,
            ]);

            return [
                'status' => 'success',
                'message' => "Inbound message reprocessed successfully.",
                'correlation_id' => $correlationId,
            ];

        } catch (\Throwable $e) {
            ConciergeRecoveryAudit::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'action' => 'reprocess_inbound',
                'initiated_by' => $actor,
                'admin_id' => $adminId,
                'previous_state' => $conversation->state,
                'proposed_state' => $conversation->state,
                'previous_step' => $conversation->current_step,
                'is_dry_run' => false,
                'status' => 'failed',
                'reason' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ];
        }
    }

    /**
     * Perform bulk recovery on multiple conversations.
     */
    public function bulkRecover(
        array $conversationIds,
        string $action,
        bool $dryRun = false,
        string $actor = 'admin',
        ?int $adminId = null
    ): array {
        $results = [
            'total_selected' => count($conversationIds),
            'action' => $action,
            'dry_run' => $dryRun,
            'eligible_count' => 0,
            'excluded_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'items' => [],
        ];

        foreach ($conversationIds as $id) {
            $conv = WhatsAppConversation::with('contact')->find($id);
            if (!$conv) {
                $results['excluded_count']++;
                $results['items'][] = [
                    'id' => $id,
                    'status' => 'not_found',
                    'reason' => 'Conversation does not exist.',
                ];
                continue;
            }

            $diag = $this->diagnosticService->diagnoseConversation($conv);

            // Execute or preview per action
            $itemResult = match ($action) {
                'release_stale_handoff' => $this->releaseStaleHandoff($conv, $dryRun, $actor, $adminId),
                'renudge_current_step' => $this->renudgeCurrentStep($conv, $dryRun, $actor, $adminId),
                'reprocess_inbound' => $this->reprocessLastInbound($conv, $dryRun, $actor, $adminId),
                default => ['status' => 'invalid_action', 'reason' => "Action '{$action}' is not recognized."],
            };

            $status = $itemResult['status'] ?? 'unknown';

            if (in_array($status, ['success', 'dry_run_passed'])) {
                $results['eligible_count']++;
                if (!$dryRun && $status === 'success') {
                    $results['success_count']++;
                }
            } else {
                $results['excluded_count']++;
                if (!$dryRun && $status === 'failed') {
                    $results['failed_count']++;
                }
            }

            $results['items'][] = array_merge([
                'id' => $conv->id,
                'phone' => $conv->contact?->phone_number,
                'step' => $conv->current_step,
            ], $itemResult);
        }

        return $results;
    }

    /**
     * Run automated periodic health check and record snapshot.
     */
    public function runHealthCheck(string $checkType = 'scheduled'): ConciergeHealthCheck
    {
        $overview = $this->diagnosticService->getOperationsOverview();

        return ConciergeHealthCheck::create([
            'check_type' => $checkType,
            'total_active_conversations' => $overview['total_active_onboarding'],
            'waiting_for_concierge' => $overview['waiting_for_concierge'],
            'waiting_for_user' => $overview['waiting_for_user'],
            'in_human_handoff' => $overview['in_human_handoff'],
            'stale_human_handoff' => $overview['stale_human_handoff'],
            'silenced_count' => $overview['silenced_conversations'],
            'stuck_count' => $overview['stuck_conversations'],
            'failed_sends_count' => $overview['failed_outbounds'],
            'auto_recovered_count' => $overview['recently_recovered'],
            'issues_requiring_human' => $overview['issues_requiring_human'],
            'issues_requiring_code' => $overview['issues_requiring_code'],
            'summary' => $overview,
        ]);
    }
}
