<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;

class StuckApplicationController extends Controller
{
    /**
     * Display a listing of the stuck/incomplete applications.
     */
    public function index(Request $request)
    {
        $query = OnboardingSession::with(['contact', 'vendor']);

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Default to not completed/cancelled
            $query->whereNotIn('status', ['completed', 'cancelled']);
        }

        $applications = $query->orderBy('last_activity_at', 'desc')->paginate(20);

        // Classifications mapped for filtering
        $statuses = [
            'in_progress' => 'In progress',
            'waiting_for_user' => 'Waiting for user',
            'waiting_for_concierge' => 'Waiting for concierge',
            'processing' => 'Processing',
            'temporarily_failed' => 'Temporarily failed',
            'stuck' => 'Stuck',
            'abandoned' => 'Abandoned',
            'human_intervention_required' => 'Human intervention required',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled'
        ];

        return view('whatsappvendorconcierge::admin.stuck_applications.index', compact('applications', 'statuses'));
    }

    /**
     * Automated recovery actions.
     */
    public function recoveryAction(Request $request, $id)
    {
        $application = OnboardingSession::findOrFail($id);
        $conversation = \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation::where('onboarding_session_id', $application->id)->latest('id')->first();

        if (!$conversation) {
            return back()->with('error', 'No conversation is linked to this application. Review it with support.');
        }

        // Legacy actions only changed status and falsely reported a successful send.
        // All recovery now uses the shared eligibility checks and preview workflow.
        return redirect()->route('admin.whatsapp.operations-center.index', ['conversation' => $conversation->id])
            ->with('info', 'Choose a recovery action and review its preview before execution.');
    }
}