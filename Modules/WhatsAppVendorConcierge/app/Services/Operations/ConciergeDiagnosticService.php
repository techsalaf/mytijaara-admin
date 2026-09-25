<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\Operations;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit;
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;
use App\Models\Vendor;

class ConciergeDiagnosticService
{
    /**
     * Get high-level operations overview metrics.
     */
    public function getOperationsOverview(): array
    {
        $cutoff = Carbon::now()->subDays(7);
        $recentConvs = WhatsAppConversation::with(['contact', 'vendor'])
            ->where('last_activity_at', '>=', $cutoff)
            ->get();

        $totalActiveOnboarding = 0;
        $waitingForConcierge = 0;
        $waitingForUser = 0;
        $inHumanHandoff = 0;
        $staleHumanHandoff = 0;
        $silencedCount = 0;
        $stuckCount = 0;
        $failedSendsCount = 0;
        $issuesRequiringCode = 0;
        $issuesRequiringHuman = 0;

        foreach ($recentConvs as $conv) {
            $diag = $this->diagnoseConversation($conv);

            if ($conv->state === 'onboarding_active' || ($conv->onboarding_session_id && !$conv->vendor_id)) {
                $totalActiveOnboarding++;
            }

            if ($diag['failure_category'] === 'silenced_onboarding') {
                $silencedCount++;
                $waitingForConcierge++;
            } elseif ($diag['failure_category'] === 'stale_human_handoff') {
                $staleHumanHandoff++;
            } elseif ($diag['failure_category'] === 'validation_failure_loop') {
                $stuckCount++;
            } elseif ($diag['failure_category'] === 'unresponsive_user') {
                $waitingForUser++;
            }

            if ($conv->state === 'human_handoff') {
                $inHumanHandoff++;
            }

            if ($diag['safety_classification'] === 'code_defect') {
                $issuesRequiringCode++;
            } elseif ($diag['safety_classification'] === 'human_required') {
                $issuesRequiringHuman++;
            }
        }

        // Count failed outbound messages in last 48h
        $failedSendsCount = WhatsAppMessage::where('direction', 'outbound')
            ->where('status', 'failed')
            ->where('created_at', '>=', Carbon::now()->subDays(2))
            ->count();

        // Pending vendors awaiting approval
        $vendorsAwaitingApproval = Vendor::whereNull('status')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        // Count recovered conversations in last 24h
        $recentlyRecovered = ConciergeRecoveryAudit::where('status', 'success')
            ->where('is_dry_run', false)
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->distinct('conversation_id')
            ->count('conversation_id');

        return [
            'total_active_onboarding' => $totalActiveOnboarding,
            'waiting_for_concierge' => $waitingForConcierge,
            'waiting_for_user' => $waitingForUser,
            'in_human_handoff' => $inHumanHandoff,
            'stale_human_handoff' => $staleHumanHandoff,
            'silenced_conversations' => $silencedCount,
            'stuck_conversations' => $stuckCount,
            'failed_outbounds' => $failedSendsCount,
            'vendors_awaiting_approval' => $vendorsAwaitingApproval,
            'recently_recovered' => $recentlyRecovered,
            'issues_requiring_human' => $issuesRequiringHuman,
            'issues_requiring_code' => $issuesRequiringCode,
            'system_health' => ($silencedCount === 0 && $staleHumanHandoff === 0 && $issuesRequiringCode === 0) ? 'healthy' : ($issuesRequiringCode > 0 ? 'critical' : 'degraded'),
        ];
    }

    /**
     * Diagnose a single conversation in deep detail.
     */
    public function diagnoseConversation(WhatsAppConversation $conversation): array
    {
        $contact = $conversation->contact;
        $state = $conversation->state;
        $step = $conversation->current_step;

        $lastInbound = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->latest('id')
            ->first();

        $lastOutbound = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $isSilenced = $lastInbound && (!$lastOutbound || $lastOutbound->id < $lastInbound->id);
        $minutesSinceInbound = $lastInbound ? Carbon::parse($lastInbound->created_at)->diffInMinutes(now()) : 99999;
        $hoursSinceInbound = $lastInbound ? Carbon::parse($lastInbound->created_at)->diffInHours(now()) : 99999;
        $serviceWindowOpen = $lastInbound ? ($hoursSinceInbound < 24) : false;

        // Check if in human handoff
        $isHumanHandoff = ($state === 'human_handoff');
        $handoffTimestamp = $conversation->updated_at ?? $conversation->last_activity_at;
        $handoffDurationHours = $isHumanHandoff && $handoffTimestamp ? Carbon::parse($handoffTimestamp)->diffInHours(now()) : 0;
        $isStaleHandoff = $isHumanHandoff && ($handoffDurationHours >= 2);

        // Check recent failure events
        $recentFailedEvents = 0;
        if ($conversation->onboarding_session_id) {
            $recentFailedEvents = OnboardingEvent::where('onboarding_session_id', $conversation->onboarding_session_id)
                ->where('event_type', 'step_failed')
                ->where('created_at', '>=', now()->subHours(12))
                ->count();
        }

        // Check recent nudge audit to prevent duplicate nudging / spam
        $recentNudgeAudit = ConciergeRecoveryAudit::where('conversation_id', $conversation->id)
            ->where('action', 'renudge_current_step')
            ->where('status', 'success')
            ->where('is_dry_run', false)
            ->latest('created_at')
            ->first();

        $nudgeCooldownMinutes = $recentNudgeAudit ? Carbon::parse($recentNudgeAudit->created_at)->diffInMinutes(now()) : 9999;
        $totalNudgesSent = ConciergeRecoveryAudit::where('conversation_id', $conversation->id)
            ->where('action', 'renudge_current_step')
            ->where('status', 'success')
            ->where('is_dry_run', false)
            ->count();

        $canNudge = ($nudgeCooldownMinutes >= 45) && ($totalNudgesSent < 3);

        // Calculate progress percentage
        $progressPct = $this->calculateProgressPercentage($step, $state);

        // Diagnosis decision tree
        $failureCategory = 'none';
        $humanDiagnosis = 'Normal conversation state. No action required.';
        $recommendedAction = 'none';
        $safetyClassification = 'none';
        $expectedNextResponder = 'user';

        if ($isStaleHandoff) {
            $failureCategory = 'stale_human_handoff';
            $humanDiagnosis = "Conversation has been in human handoff for {$handoffDurationHours} hours without resolution.";
            $recommendedAction = 'release_stale_handoff';
            $safetyClassification = 'safe_manual';
            $expectedNextResponder = 'human_agent';
        } elseif ($isSilenced && ($state === 'onboarding_active' || $conversation->onboarding_session_id)) {
            $failureCategory = 'silenced_onboarding';
            $humanDiagnosis = "User replied at " . ($lastInbound ? $lastInbound->created_at->format('M d, H:i') : 'recently') .
                " with '" . Str::limit((string)($lastInbound->raw_text ?? 'media'), 30) . "', but no concierge response was sent.";
            $expectedNextResponder = 'concierge';

            if ($serviceWindowOpen) {
                if ($canNudge) {
                    $recommendedAction = 'renudge_current_step';
                    $safetyClassification = 'safe_manual';
                } else {
                    $recommendedAction = 'assign_human';
                    $safetyClassification = 'human_required';
                    $humanDiagnosis .= " Maximum automatic nudges reached ({$totalNudgesSent}/3). Operator intervention recommended.";
                }
            } else {
                $recommendedAction = 'send_resume_template';
                $safetyClassification = 'safe_manual';
                $humanDiagnosis .= " 24-hour WhatsApp service window is closed; an approved template is required.";
            }
        } elseif ($recentFailedEvents >= 3) {
            $failureCategory = 'validation_failure_loop';
            $humanDiagnosis = "User has failed input validation {$recentFailedEvents} times consecutively on step '{$step}'.";
            $recommendedAction = 'assign_human';
            $safetyClassification = 'human_required';
            $expectedNextResponder = 'human_agent';
        } elseif ($isHumanHandoff) {
            $failureCategory = 'in_human_handoff';
            $humanDiagnosis = "Conversation actively assigned to human operator.";
            $recommendedAction = 'none';
            $safetyClassification = 'human_required';
            $expectedNextResponder = 'human_agent';
        } elseif ($lastOutbound && (!$lastInbound || $lastInbound->id < $lastOutbound->id)) {
            $failureCategory = 'unresponsive_user';
            $hoursWaiting = Carbon::parse($lastOutbound->created_at)->diffInHours(now());
            if ($hoursWaiting >= 24) {
                $humanDiagnosis = "Concierge sent prompt {$hoursWaiting}h ago. User has not yet responded.";
                $recommendedAction = 'send_resume_template';
                $safetyClassification = 'safe_manual';
            } else {
                $humanDiagnosis = "Waiting for user response to step '{$step}'. Sent {$hoursWaiting}h ago.";
                $recommendedAction = 'none';
                $safetyClassification = 'none';
            }
            $expectedNextResponder = 'user';
        }

        return [
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'phone' => $contact->phone_number,
            'name' => $contact->name ?? 'Vendor Applicant',
            'state' => $state,
            'current_step' => $step,
            'progress_percentage' => $progressPct,
            'last_inbound' => $lastInbound ? [
                'id' => $lastInbound->id,
                'text' => $lastInbound->raw_text,
                'type' => $lastInbound->type,
                'created_at' => $lastInbound->created_at->toIso8601String(),
                'readable_time' => $lastInbound->created_at->diffForHumans(),
            ] : null,
            'last_outbound' => $lastOutbound ? [
                'id' => $lastOutbound->id,
                'text' => $lastOutbound->raw_text,
                'type' => $lastOutbound->type,
                'status' => $lastOutbound->status,
                'created_at' => $lastOutbound->created_at->toIso8601String(),
                'readable_time' => $lastOutbound->created_at->diffForHumans(),
            ] : null,
            'is_silenced' => $isSilenced,
            'service_window_open' => $serviceWindowOpen,
            'failure_category' => $failureCategory,
            'human_diagnosis' => $humanDiagnosis,
            'recommended_action' => $recommendedAction,
            'safety_classification' => $safetyClassification,
            'expected_next_responder' => $expectedNextResponder,
            'can_nudge' => $canNudge,
            'nudges_sent_count' => $totalNudgesSent,
            'minutes_since_last_nudge' => $nudgeCooldownMinutes,
            'correlation_id' => 'DIAG-' . strtoupper(Str::random(10)),
        ];
    }

    /**
     * Calculate onboarding step completion percentage.
     */
    protected function calculateProgressPercentage(?string $step, string $state): int
    {
        if ($state === 'onboarding_completed') {
            return 100;
        }

        $steps = [
            'welcome' => 5,
            'business_basics' => 15,
            'module_selection' => 25,
            'category_selection' => 35,
            'location' => 45,
            'zone_selection' => 55,
            'operating_hours' => 65,
            'delivery_time' => 70,
            'owner_info' => 75,
            'account_password' => 80,
            'store_branding' => 85,
            'cover_branding' => 90,
            'business_plan' => 93,
            'terms_acceptance' => 95,
            'privacy_acceptance' => 97,
            'review_submit' => 99,
        ];

        return $steps[$step] ?? 10;
    }
}
