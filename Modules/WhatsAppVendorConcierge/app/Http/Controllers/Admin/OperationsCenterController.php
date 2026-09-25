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

        $items = $this->diagnosticService->scan($filter, $search);
        if ($request->filled('conversation')) $items = $items->where('conversation.id', (int) $request->input('conversation'))->values();
        $page = max(1, (int) $request->input('page', 1));
        $conversations = new \Illuminate\Pagination\LengthAwarePaginator($items->forPage($page, 25)->values(), $items->count(), 25, $page,
            ['path'=>$request->url(), 'query'=>$request->query()]);
        $diagnosedItems = $conversations->items();

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
            ->orderBy('created_at', 'desc')
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
        return $this->preview($request, [(int) $id], false);
    }

    public function bulkPreview(Request $request)
    {
        $request->validate(['conversation_ids'=>'required|array|min:1|max:100', 'conversation_ids.*'=>'integer|distinct|exists:whatsapp_conversations,id']);
        return $this->preview($request, array_map('intval', $request->input('conversation_ids')), true);
    }

    private function preview(Request $request, array $ids, bool $bulk)
    {
        $request->validate(['action'=>'required|in:renudge_current_step,release_stale_handoff,reprocess_inbound,assign_human']);
        $result = $this->recoveryService->bulkRecover($ids, $request->action, true, 'admin', auth('admin')->id());
        $token = (string) \Illuminate\Support\Str::uuid();
        sort($ids);
        \Illuminate\Support\Facades\Cache::put('concierge-preview-'.$token, ['ids'=>$ids, 'action'=>$request->action, 'admin'=>auth('admin')->id(), 'snapshot'=>$this->snapshot($ids)], now()->addMinutes(5));
        $response = $bulk ? $result : ($result['items'][0] ?? []);
        $response['preview_token'] = $token;
        return response()->json($response);
    }

    public function executeAction(Request $request, $id)
    {
        return $this->executePreview($request, [(int) $id], false);
    }

    public function bulkExecute(Request $request)
    {
        $request->validate(['conversation_ids'=>'required|array|min:1|max:100', 'conversation_ids.*'=>'integer|distinct|exists:whatsapp_conversations,id']);
        return $this->executePreview($request, array_map('intval', $request->input('conversation_ids')), true);
    }

    private function executePreview(Request $request, array $ids, bool $bulk)
    {
        $request->validate(['preview_token'=>'required|uuid', 'action'=>'required|string']);
        sort($ids);
        $expected = ['ids'=>$ids, 'action'=>$request->action, 'admin'=>auth('admin')->id(), 'snapshot'=>$this->snapshot($ids)];
        $preview = \Illuminate\Support\Facades\Cache::pull('concierge-preview-'.$request->preview_token);
        abort_unless($preview === $expected, 409, 'Preview expired, changed, or already executed. Preview the action again.');
        $result = $this->recoveryService->bulkRecover($ids, $request->action, false, 'admin', auth('admin')->id());
        return response()->json($bulk ? $result : ($result['items'][0] ?? []));
    }

    private function snapshot(array $ids): string
    {
        return hash('sha256', WhatsAppConversation::whereIn('id',$ids)->orderBy('id')
            ->get(['id','state','current_step','onboarding_session_id','updated_at'])->toJson());
    }

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
