<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WhatsAppVendorConcierge\app\Models\ResumeCampaign;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;

class ResumeCampaignController extends Controller
{
    /**
     * Display a listing of campaigns.
     */
    public function index()
    {
        $campaigns = ResumeCampaign::orderBy('created_at', 'desc')->paginate(20);
        return view('whatsappvendorconcierge::admin.resume_campaigns.index', compact('campaigns'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('whatsappvendorconcierge::admin.resume_campaigns.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'meta_template_name' => 'required|string|max:255',
            'audience_status' => 'required|array',
        ]);

        $campaign = ResumeCampaign::create([
            'name' => $validated['name'],
            'meta_template_name' => $validated['meta_template_name'],
            'audience_criteria' => [
                'statuses' => $validated['audience_status']
            ],
            'status' => 'draft',
        ]);

        return redirect()->route('admin.whatsapp.resume-campaigns.index')
            ->with('success', 'Resume campaign created successfully.');
    }

    /**
     * Launch the campaign (simulated).
     */
    public function launch(ResumeCampaign $campaign)
    {
        // 1. Fetch audience
        $statuses = $campaign->audience_criteria['statuses'] ?? ['stuck', 'abandoned'];
        
        $audience = OnboardingSession::whereIn('status', $statuses)->get();

        // 2. Dispatch jobs to send meta template with structured quick-replies to each audience member
        // foreach ($audience as $session) {
        //     SendResumePromptJob::dispatch($session, $campaign->meta_template_name);
        // }
        
        $campaign->update([
            'status' => 'active',
            'sent_count' => $audience->count(),
        ]);

        return redirect()->route('admin.whatsapp.resume-campaigns.index')
            ->with('success', 'Campaign launched successfully.');
    }
}
