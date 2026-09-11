<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;

class OnboardingSessionController extends Controller
{
    /**
     * List onboarding sessions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = OnboardingSession::with(['contact', 'vendor', 'store']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('current_step')) {
            $query->where('current_step', $request->input('current_step'));
        }

        $sessions = $query->orderBy('updated_at', 'desc')->paginate(20);

        return response()->json($sessions);
    }

    /**
     * Show single onboarding session details with events.
     */
    public function show(int $id): JsonResponse
    {
        $session = OnboardingSession::with(['contact', 'vendor', 'store'])->findOrFail($id);

        $events = OnboardingEvent::where('session_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'session' => $session,
            'events' => $events,
        ]);
    }

    /**
     * Get onboarding funnel analytics.
     */
    public function analytics(): JsonResponse
    {
        $totalSessions = OnboardingSession::count();
        $submitted = OnboardingSession::whereIn('status', ['submitted', 'approved'])->count();
        $approved = OnboardingSession::where('status', 'approved')->count();
        $abandoned = OnboardingSession::where('status', 'abandoned')->count();
        $inProgress = OnboardingSession::where('status', 'started')->count();

        // Step completion counts
        $stepDropoffs = OnboardingSession::select('current_step', DB::raw('count(*) as count'))
            ->where('status', 'started')
            ->groupBy('current_step')
            ->pluck('count', 'current_step');

        $eventsCount = OnboardingEvent::select('event_type', DB::raw('count(*) as count'))
            ->groupBy('event_type')
            ->pluck('count', 'event_type');

        $conversionRate = $totalSessions > 0
            ? round(($submitted / $totalSessions) * 100, 2)
            : 0;

        return response()->json([
            'metrics' => [
                'total_started' => $totalSessions,
                'in_progress' => $inProgress,
                'submitted' => $submitted,
                'approved' => $approved,
                'abandoned' => $abandoned,
                'conversion_rate_percent' => $conversionRate,
            ],
            'step_distribution_in_progress' => $stepDropoffs,
            'events_summary' => $eventsCount,
        ]);
    }
}
