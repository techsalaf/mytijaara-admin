<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\Operations;

use Illuminate\Support\Facades\{Cache, DB, Log};
use Illuminate\Support\Str;
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppConversation, ConciergeRecoveryAudit, ConciergeHealthCheck};
use Modules\WhatsAppVendorConcierge\app\Services\{WhatsAppGateway, VendorOnboardingService, SupportCaseService};

class ConciergeRecoveryService
{
    public function __construct(protected ConciergeDiagnosticService $diagnosticService, protected WhatsAppGateway $gateway,
        protected VendorOnboardingService $onboardingService, protected SupportCaseService $supportCaseService) {}

    public function releaseStaleHandoff(WhatsAppConversation $conversation, bool $dryRun = true, string $actor = 'admin', ?int $adminId = null, ?string $reason = null): array
    { return $this->perform($conversation, 'release_stale_handoff', $dryRun, $actor, $adminId, $reason); }

    public function renudgeCurrentStep(WhatsAppConversation $conversation, bool $dryRun = true, string $actor = 'admin', ?int $adminId = null, ?string $reason = null): array
    { return $this->perform($conversation, 'renudge_current_step', $dryRun, $actor, $adminId, $reason); }

    public function reprocessLastInbound(WhatsAppConversation $conversation, bool $dryRun = true, string $actor = 'admin', ?int $adminId = null): array
    { return $this->perform($conversation, 'reprocess_inbound', $dryRun, $actor, $adminId, null); }

    public function assignHuman(WhatsAppConversation $conversation, bool $dryRun = true, string $actor = 'admin', ?int $adminId = null, ?string $reason = null): array
    { return $this->perform($conversation, 'assign_human', $dryRun, $actor, $adminId, $reason); }

    private function perform(WhatsAppConversation $conversation, string $action, bool $dryRun, string $actor, ?int $adminId, ?string $reason): array
    {
        $lock = Cache::lock('concierge-recovery-contact-'.$conversation->contact_id, 180);
        if (!$lock->get()) return ['status'=>'excluded', 'reason'=>'Another recovery is running for this contact.'];
        try {
            $conversation->refresh()->load(['contact', 'onboardingSession']);
            $d = $this->diagnosticService->diagnoseConversation($conversation);
            $target = $d['active_application'] ? 'onboarding_active' : ($conversation->contact?->vendor_id ? 'ai_active' : 'welcome');
            $result = ['conversation_id'=>$conversation->id, 'phone'=>$d['phone'], 'action'=>$action,
                'previous_state'=>$conversation->state, 'proposed_state'=>$action === 'release_stale_handoff' ? $target : ($action === 'assign_human' ? 'human_handoff' : $conversation->state),
                'step'=>$conversation->current_step, 'service_window_open'=>$d['service_window_open'], 'can_nudge'=>$d['can_nudge'],
                'dry_run'=>$dryRun, 'correlation_id'=>'REC-'.Str::uuid(), 'status'=>'excluded'];
            $excluded = match ($action) {
                'release_stale_handoff' => $d['failure_category'] !== 'stale_human_handoff' ? 'Only an old handoff with no open support ticket can be released.' : null,
                'renudge_current_step' => !$d['can_nudge'] ? 'Prompt excluded: check active draft, support ownership, blocked contact, 24-hour window, cooldown, and daily limit.' : null,
                'assign_human' => !$conversation->contact || $conversation->contact->is_blocked ? 'Contact is unavailable or blocked.' : null,
                'reprocess_inbound' => 'Historical messages have no reliable processing checkpoint. Replaying could repeat a completed action. Inspect the chat, resend the current prompt when eligible, or escalate to support.',
                default => 'Unknown action.',
            };
            $result['is_eligible'] = $excluded === null;
            $result['reason'] = $excluded ?? $reason ?? 'Administrator reviewed recovery';
            $result['message'] = $excluded ?? match ($action) {
                'renudge_current_step' => 'Resend the current '.strtolower($d['step_label']).' question. No application data will be reset.',
                'release_stale_handoff' => 'Return this orphaned handoff to '.str_replace('_', ' ', $target).'. No customer message will be sent.',
                default => 'Open a support ticket and pause automation. No customer message will be sent.',
            };
            if (!$excluded && $dryRun) $result['status'] = 'dry_run_passed';
            if (!$excluded && !$dryRun) {
                try {
                    if ($action === 'renudge_current_step') {
                        $this->gateway->clearLastSendResult();
                        $this->onboardingService->sendStepPrompt($conversation, $conversation->contact, $conversation->current_step, $this->gateway);
                        $send = $this->gateway->lastSendResult();
                        if (empty($send['messages'][0]['id'])) throw new \RuntimeException('WhatsApp did not accept the prompt. Inspect provider configuration and delivery logs.');
                        $result['message_id'] = $send['messages'][0]['id'];
                        $result['message'] = 'WhatsApp accepted the prompt. Delivery and read receipts will update the inbox.';
                    } else {
                        DB::transaction(function () use ($conversation, $action, $target, $reason) {
                            if ($action === 'assign_human') {
                                if (!$this->supportCaseService->getActiveCase($conversation->contact)) {
                                    $this->supportCaseService->createCase($conversation->contact, 'Concierge recovery needs human help', 'general', 'medium', $reason, $conversation);
                                }
                                $conversation->transitionTo('human_handoff');
                            } else {
                                $conversation->transitionTo($target);
                            }
                        });
                    }
                    $result['status'] = 'success';
                } catch (\Throwable $error) {
                    $result['status'] = 'failed';
                    $result['error'] = 'Recovery could not be completed. Use the correlation ID to investigate.';
                    Log::error('Concierge recovery failed', ['correlation_id'=>$result['correlation_id'], 'exception'=>$error::class]);
                }
            }
            ConciergeRecoveryAudit::create([
                'conversation_id'=>$conversation->id, 'contact_id'=>$conversation->contact_id, 'action'=>$action,
                'initiated_by'=>$actor, 'admin_id'=>$adminId, 'previous_state'=>$result['previous_state'], 'proposed_state'=>$result['proposed_state'],
                'previous_step'=>$result['step'], 'is_dry_run'=>$dryRun, 'status'=>$result['status'], 'reason'=>$result['reason'],
                'details'=>['message_id'=>$result['message_id'] ?? null, 'eligible'=>$result['is_eligible'], 'result'=>$result['message']],
                'correlation_id'=>$result['correlation_id'],
            ]);
            return $result;
        } finally { $lock->release(); }
    }

    public function bulkRecover(array $conversationIds, string $action, bool $dryRun = true, string $actor = 'admin', ?int $adminId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $conversationIds)));
        if (count($ids) > 100) throw new \InvalidArgumentException('Select at most 100 conversations per batch.');
        $result = ['total_selected'=>count($ids), 'action'=>$action, 'dry_run'=>$dryRun, 'eligible_count'=>0, 'excluded_count'=>0, 'success_count'=>0, 'failed_count'=>0, 'items'=>[]];
        foreach ($ids as $id) {
            $conversation = WhatsAppConversation::find($id);
            $item = $conversation ? $this->perform($conversation, $action, $dryRun, $actor, $adminId, null) : ['status'=>'excluded','reason'=>'Conversation not found.'];
            $item['id'] = $id;
            $eligible = $item['is_eligible'] ?? false;
            $result[$eligible ? 'eligible_count' : 'excluded_count']++;
            if ($item['status'] === 'success') $result['success_count']++;
            if ($item['status'] === 'failed') $result['failed_count']++;
            $result['items'][] = $item;
        }
        return $result;
    }

    public function runHealthCheck(string $checkType = 'scheduled'): ConciergeHealthCheck
    {
        $o = $this->diagnosticService->getOperationsOverview();
        return ConciergeHealthCheck::create(['check_type'=>$checkType, 'total_active_conversations'=>$o['total_active_onboarding'],
            'waiting_for_concierge'=>$o['waiting_for_concierge'], 'waiting_for_user'=>$o['waiting_for_user'], 'in_human_handoff'=>$o['in_human_handoff'],
            'stale_human_handoff'=>$o['stale_human_handoff'], 'silenced_count'=>$o['silenced_conversations'], 'stuck_count'=>$o['stuck_conversations'],
            'failed_sends_count'=>$o['failed_outbounds'], 'auto_recovered_count'=>0, 'issues_requiring_human'=>$o['issues_requiring_human'],
            'issues_requiring_code'=>$o['issues_requiring_code'], 'summary'=>$o]);
    }
}
