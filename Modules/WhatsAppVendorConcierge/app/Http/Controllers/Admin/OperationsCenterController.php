<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Brian2694\Toastr\Facades\Toastr;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeRecoveryAudit;
use Modules\WhatsAppVendorConcierge\app\Models\ConciergeHealthCheck;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeDiagnosticService;
use Modules\WhatsAppVendorConcierge\app\Services\Operations\ConciergeRecoveryService;

class OperationsCenterController extends Controller
{
    public function __construct(
        protected ConciergeDiagnosticService $diagnosticService,
        protected ConciergeRecoveryService $recoveryService
    ) {}

    /**
     * Operations Centre dashboard overview and triage list.
     */
    public function index(Request $request)
    {
        $overview = $this->diagnosticService->getOperationsOverview();

        $filter = $request->input('filter', 'all');
        $search = $request->input('search');

        $query = WhatsAppConversation::with(['contact', 'vendor'])
            ->whereNotNull('last_activity_at')
            ->orderBy('last_activity_at', 'desc');

        if ($search) {
            $query->whereHas('contact', function ($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // Apply primary state / triage filter
        if ($filter === 'waiting_concierge') {
            // Silenced onboarding
            $query->whereIn('state', ['onboarding_active', 'ai_active']);
        } elseif ($filter === 'waiting_user') {
            $query->where('state', 'onboarding_active');
        } elseif ($filter === 'human_handoff') {
            $query->where('state', 'human_handoff');
        } elseif ($filter === 'stale_handoff') {
            $query->where('state', 'human_handoff')
                  ->where('updated_at', '<=', now()->subHours(2));
        }

        $conversations = $query->paginate(25);

        // Augment each conversation with live diagnosis
        $diagnosedItems = [];
        foreach ($conversations as $conv) {
            $diag = $this->diagnosticService->diagnoseConversation($conv);

            // Filter out items if specific filter is set
            if ($filter === 'waiting_concierge' && $diag['failure_category'] !== 'silenced_onboarding') {
                continue;
            }
            if ($filter === 'waiting_user' && $diag['failure_category'] !== 'unresponsive_user') {
                continue;
            }

            $diagnosedItems[] = [
                'conversation' => $conv,
                'diag' => $diag,
            ];
        }

        // Recent recovery audits
        $recentAudits = ConciergeRecoveryAudit::with(['conversation.contact'])
            ->latest('id')
            ->limit(8)
            ->get();

        // Latest health check
        $latestHealthCheck = ConciergeHealthCheck::latest('id')->first();

        return view('whatsappvendorconcierge::admin.operations_center.index', compact(
            'overview',
            'filter',
            'search',
            'conversations',
            'diagnosedItems',
            'recentAudits',
            'latestHealthCheck'
        ));
    }

    /**
     * Show single conversation deep diagnosis.
     */
    public function show($id)
    {
        $conversation = WhatsAppConversation::with(['contact', 'vendor'])->findOrFail($id);
        $diag = $this->diagnosticService->diagnoseConversation($conversation);

        $messages = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->orderBy('id', 'desc')
            ->limit(30)
            ->get()
            ->reverse();

        $events = [];
        if ($conversation->onboarding_session_id) {
            $events = OnboardingEvent::where('onboarding_session_id', $conversation->onboarding_session_id)
                ->orderBy('id', 'desc')
                ->limit(20)
                ->get();
        }

        $audits = ConciergeRecoveryAudit::where('conversation_id', $conversation->id)
            ->latest('id')
            ->get();

        return view('whatsappvendorconcierge::admin.operations_center.show', compact(
            'conversation',
            'diag',
            'messages',
            'events',
            'audits'
        ));
    }

    /**
     * Dry run preview of a recovery action before executing.
     */
    public function previewAction(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|string|in:renudge_current_step,release_stale_handoff,reprocess_inbound',
        ]);

        $conv = WhatsAppConversation::with('contact')->findOrFail($id);
        $action = $request->input('action');

        $result = match ($action) {
            'renudge_current_step' => $this->recoveryService->renudgeCurrentStep($conv, dryRun: true),
            'release_stale_handoff' => $this->recoveryService->releaseStaleHandoff($conv, dryRun: true),
            'reprocess_inbound' => $this->recoveryService->reprocessLastInbound($conv, dryRun: true),
        };

        return response()->json($result);
    }

    /**
     * Execute confirmed recovery action.
     */
    public function executeAction(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|string|in:renudge_current_step,release_stale_handoff,reprocess_inbound',
            'reason' => 'nullable|string|max:500',
        ]);

        $conv = WhatsAppConversation::with('contact')->findOrFail($id);
        $action = $request->input('action');
        $reason = $request->input('reason');
        $adminId = auth('admin')->id() ?? auth()->id();

        $result = match ($action) {
            'renudge_current_step' => $this->recoveryService->renudgeCurrentStep($conv, dryRun: false, actor: 'admin', adminId: $adminId, reason: $reason),
            'release_stale_handoff' => $this->recoveryService->releaseStaleHandoff($conv, dryRun: false, actor: 'admin', adminId: $adminId, reason: $reason),
            'reprocess_inbound' => $this->recoveryService->reprocessLastInbound($conv, dryRun: false, actor: 'admin', adminId: $adminId),
        };

        if (($result['status'] ?? '') === 'success') {
            Toastr::success($result['message'] ?? 'Recovery action executed successfully.');
        } else {
            Toastr::error($result['reason'] ?? $result['error'] ?? 'Recovery action could not be completed.');
        }

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back();
    }

    /**
     * Preview bulk recovery action (dry run with eligibility breakdown).
     */
    public function bulkPreview(Request $request)
    {
        $request->validate([
            'conversation_ids' => 'required|array|min:1',
            'conversation_ids.*' => 'integer|exists:whatsapp_conversations,id',
            'action' => 'required|string|in:renudge_current_step,release_stale_handoff,reprocess_inbound',
        ]);

        $ids = $request->input('conversation_ids');
        $action = $request->input('action');

        $preview = $this->recoveryService->bulkRecover($ids, $action, dryRun: true);

        return response()->json($preview);
    }

    /**
     * Execute bulk recovery action.
     */
    public function bulkExecute(Request $request)
    {
        $request->validate([
            'conversation_ids' => 'required|array|min:1',
            'conversation_ids.*' => 'integer|exists:whatsapp_conversations,id',
            'action' => 'required|string|in:renudge_current_step,release_stale_handoff,reprocess_inbound',
        ]);

        $ids = $request->input('conversation_ids');
        $action = $request->input('action');
        $adminId = auth('admin')->id() ?? auth()->id();

        $results = $this->recoveryService->bulkRecover($ids, $action, dryRun: false, actor: 'admin', adminId: $adminId);

        Toastr::success("Bulk {$action} executed: {$results['success_count']} succeeded, {$results['excluded_count']} excluded.");

        if ($request->wantsJson()) {
            return response()->json($results);
        }

        return back();
    }

    /**
     * Trigger on-demand system health check snapshot.
     */
    public function runHealthCheck()
    {
        $check = $this->recoveryService->runHealthCheck('on_demand');
        Toastr::success("Health check scan completed. Active: {$check->total_active_conversations}, Silenced: {$check->silenced_count}, Stale Handoffs: {$check->stale_human_handoff}.");

        return back();
    }

    /**
     * View full recovery audit logs.
     */
    public function audits(Request $request)
    {
        $query = ConciergeRecoveryAudit::with(['conversation.contact'])
            ->latest('id');

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $audits = $query->paginate(30);

        return view('whatsappvendorconcierge::admin.operations_center.audits', compact('audits'));
    }
}
