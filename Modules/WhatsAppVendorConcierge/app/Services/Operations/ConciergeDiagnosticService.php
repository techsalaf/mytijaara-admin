<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\Operations;

use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppConversation, WhatsAppMessage, OnboardingSession, OnboardingEvent, ConciergeRecoveryAudit};
use Modules\WhatsAppVendorConcierge\app\Services\SupportCaseService;
use App\Models\Store;

class ConciergeDiagnosticService
{
    public function scan(string $filter = 'all', ?string $search = null): \Illuminate\Support\Collection
    {
        return WhatsAppConversation::with(['contact', 'vendor', 'onboardingSession'])->latest('last_activity_at')->get()
            ->map(fn ($conversation) => ['conversation' => $conversation, 'diag' => $this->diagnoseConversation($conversation)])
            ->filter(function ($item) use ($filter, $search) {
                $d = $item['diag'];
                if ($search && !str_contains(strtolower($d['name'].' '.$d['phone']), strtolower($search))) return false;
                return match ($filter) {
                    'silenced', 'waiting_concierge' => $d['is_silenced'],
                    'waiting_user' => $d['failure_category'] === 'unresponsive_user',
                    'human_handoff' => $d['state'] === 'human_handoff' || $d['support_case_id'],
                    'stale_handoff' => $d['failure_category'] === 'stale_human_handoff',
                    'failed' => $d['failure_category'] === 'outbound_failed',
                    'active' => $d['active_application'],
                    'stuck' => in_array($d['failure_category'], ['silenced_onboarding', 'stale_human_handoff', 'validation_failure_loop', 'outbound_failed', 'inconsistent_application']),
                    'technical' => $d['safety_classification'] === 'code_defect',
                    default => true,
                };
            })->values();
    }

    public function getOperationsOverview(): array
    {
        $rows = $this->scan()->pluck('diag');
        $count = fn ($key, $value) => $rows->where($key, $value)->count();
        $silenced = $count('is_silenced', true);
        $stale = $count('failure_category', 'stale_human_handoff');
        $code = $count('safety_classification', 'code_defect');
        $failed = $count('failure_category', 'outbound_failed');
        return [
            'total_active_onboarding' => $count('active_application', true),
            'waiting_for_concierge' => $silenced,
            'waiting_for_user' => $count('failure_category', 'unresponsive_user'),
            'in_human_handoff' => $rows->filter(fn ($d) => $d['state'] === 'human_handoff' || $d['support_case_id'])->count(),
            'stale_human_handoff' => $stale,
            'silenced_conversations' => $silenced,
            'stuck_conversations' => $count('failure_category', 'validation_failure_loop'),
            'failed_outbounds' => $failed,
            'vendors_awaiting_approval' => Store::whereHas('vendor', fn ($q) => $q->whereNull('status'))->count(),
            'recently_recovered' => ConciergeRecoveryAudit::where('status', 'success')->where('is_dry_run', false)->where('created_at', '>=', now()->subDay())->distinct()->count('conversation_id'),
            'issues_requiring_human' => $count('safety_classification', 'human_required'),
            'issues_requiring_code' => $code,
            'system_health' => $code ? 'critical' : (($silenced + $stale + $failed) ? 'degraded' : 'healthy'),
        ];
    }

    public function diagnoseConversation(WhatsAppConversation $conversation): array
    {
        $contact = $conversation->contact;
        $session = $conversation->onboardingSession;
        $state = $conversation->state;
        $step = $conversation->current_step;
        $in = $conversation->messages()->where('direction', 'inbound')->latest('created_at')->latest('id')->first();
        $out = $conversation->messages()->where('direction', 'outbound')->latest('created_at')->latest('id')->first();
        $case = $contact ? app(SupportCaseService::class)->getActiveCase($contact) : null;
        $active = $session && $session->canResume() && !$session->vendor_id && !$contact?->vendor_id;
        $window = $in && $in->created_at->gt(now()->subHours(24));
        $awaiting = $in && (!$out || $out->created_at->lt($in->created_at) || $out->status === 'failed');
        $silenced = $awaiting && $in->created_at->lte(now()->subMinutes(2)) && !in_array($state, ['human_handoff', 'closed', 'expired', 'onboarding_paused']);
        $lastTransition = $session ? OnboardingEvent::where('onboarding_session_id', $session->id)->whereIn('event_type', ['step_completed', 'application_submitted'])->latest('id')->first() : null;
        $failures = $session ? OnboardingEvent::where('onboarding_session_id', $session->id)->where('event_type', 'step_failed')->where('step', $step)->where('created_at', '>=', $lastTransition?->created_at ?? now()->subHours(12))->count() : 0;
        $nudges = ConciergeRecoveryAudit::where('contact_id', $conversation->contact_id)->where('action', 'renudge_current_step')->where('is_dry_run', false)->whereIn('status', ['success', 'failed']);
        $lastNudge = (clone $nudges)->latest('id')->first();
        $nudgeCount = (clone $nudges)->where('created_at', '>=', now()->subDay())->count();
        $elapsed = $lastNudge ? max(0, (int) $lastNudge->created_at->diffInMinutes(now())) : 9999;
        $cooldown = (int) config('whatsapp-vendor-concierge.operations.nudge_cooldown_minutes', 45);
        $max = (int) config('whatsapp-vendor-concierge.operations.max_nudges_per_day', 3);
        $canNudge = $active && in_array($step, OnboardingSession::getSteps(), true) && $state === 'onboarding_active' && !$contact?->is_blocked && !$case && $window && $elapsed >= $cooldown && $nudgeCount < $max;
        $category = 'none'; $diagnosis = 'No action is needed.'; $action = 'none'; $safety = 'none'; $responder = 'user';
        if (!$contact || $contact->is_blocked) {
            $category = 'blocked'; $diagnosis = 'Messaging is disabled for this contact.'; $safety = 'human_required'; $silenced = false;
        } elseif (in_array($state, ['closed', 'expired', 'onboarding_paused'])) {
            $category = 'paused'; $diagnosis = 'This conversation is paused or closed. Inactivity alone is not a reason to restart it.'; $silenced = false;
        } elseif ($state === 'human_handoff' || $case) {
            $silenced = false; $responder = 'human_agent'; $safety = 'human_required';
            $stale = !$case && $conversation->updated_at?->lte(now()->subHours(2));
            $category = $stale ? 'stale_human_handoff' : 'in_human_handoff';
            $diagnosis = $case ? 'An open support ticket needs a human response. Automation must not close it.' : 'Human handoff has no open support ticket.';
            $action = $stale ? 'release_stale_handoff' : 'assign_human';
            if ($stale) $safety = 'safe_manual';
        } elseif ($session && in_array($session->status, ['submitted', 'approved', 'rejected'])) {
            $category = 'completed'; $responder = 'admin'; $silenced = false;
            $diagnosis = 'Application submitted. Review the store; do not restart or resend onboarding questions.';
            if (!$session->store_id || !Store::whereKey($session->store_id)->exists()) {
                $category = 'inconsistent_application'; $diagnosis = 'Submitted application has no visible linked store. Engineering must inspect the relationship and access scope.'; $safety = 'code_defect'; $action = 'investigate';
            }
        } elseif ($out?->status === 'failed') {
            $category = 'outbound_failed'; $diagnosis = 'The last reply was rejected by WhatsApp. Check delivery details before retrying.'; $responder = 'concierge'; $safety = 'human_required'; $action = $canNudge ? 'renudge_current_step' : 'investigate';
        } elseif ($failures >= 3) {
            $category = 'validation_failure_loop'; $diagnosis = 'Repeated validation failures at the same step need a human explanation.'; $safety = 'human_required'; $action = 'assign_human'; $responder = 'human_agent';
        } elseif ($silenced) {
            $category = 'silenced_onboarding'; $responder = 'concierge';
            $diagnosis = 'The latest customer message has no recorded successful reply. Check the chat and delivery evidence.';
            $action = $canNudge ? 'renudge_current_step' : ($window ? 'assign_human' : 'send_resume_template');
            $safety = $canNudge ? 'safe_manual' : 'human_required';
        } elseif ($awaiting) {
            $category = 'processing'; $diagnosis = 'The latest message arrived less than two minutes ago. Allow processing to finish.'; $responder = 'concierge';
        } elseif ($out) {
            $category = 'unresponsive_user'; $diagnosis = 'The concierge has replied. The customer is expected to respond; no repair is needed.';
        }
        $message = fn ($m) => $m ? ['id'=>$m->id, 'text'=>$m->raw_text, 'type'=>$m->type, 'status'=>$m->status, 'created_at'=>$m->created_at->toIso8601String(), 'readable_time'=>$m->created_at->diffForHumans()] : null;
        $steps = OnboardingSession::getSteps(); $position = array_search($step, $steps, true);
        return [
            'conversation_id'=>$conversation->id, 'contact_id'=>$contact?->id, 'phone'=>$contact?->phone_number ?? '',
            'name'=>$contact?->display_name ?: ($session?->collected_data['business_name'] ?? 'Vendor applicant'),
            'state'=>$state, 'current_step'=>$step, 'step_label'=>ucwords(str_replace('_', ' ', $step ?? 'No active step')),
            'active_application'=>(bool) $active, 'progress_percentage'=>$category === 'completed' ? 100 : ($position === false ? 0 : (int) round(100*$position/count($steps))),
            'last_inbound'=>$message($in), 'last_outbound'=>$message($out), 'is_silenced'=>(bool)$silenced, 'service_window_open'=>(bool)$window,
            'failure_category'=>$category, 'human_diagnosis'=>$diagnosis, 'recommended_action'=>$action, 'safety_classification'=>$safety,
            'expected_next_responder'=>$responder, 'can_nudge'=>(bool)$canNudge, 'nudges_sent_count'=>$nudgeCount, 'minutes_since_last_nudge'=>$elapsed,
            'support_case_id'=>$case?->id, 'last_transition_at'=>$lastTransition?->created_at?->toIso8601String(),
            'correlation_id'=>'CONV-'.$conversation->id.'-MSG-'.($in?->id ?? 0),
        ];
    }
}
