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
        $action = $request->input('action');

        switch ($action) {
            case 'retry_failed':
                // Logic to retry failed processing
                $application->status = 'processing';
                $application->save();
                // \Log::info("Retrying failed processing for application {$id}");
                break;

            case 'restore_state':
                // Logic to restore last valid state
                $application->status = 'in_progress';
                $application->save();
                // \Log::info("Restored valid state for application {$id}");
                break;

            case 'send_prompt':
                // Logic to send resume prompt
                $application->status = 'waiting_for_user';
                $application->save();
                // \Log::info("Sent resume prompt for application {$id}");
                // Send Meta Template message here
                break;
                
            default:
                return back()->with('error', 'Invalid action selected.');
        }

        return back()->with('success', 'Recovery action executed successfully.');
    }
}
