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

    /** Bulk launch is unavailable until per-recipient delivery accounting is implemented. */
    public function launch(ResumeCampaign $campaign)
    {
        return redirect()->route('admin.whatsapp.resume-campaigns.index')
            ->with('error', 'Campaign saved as a draft. Bulk sending is not available yet. Use Operations Centre to preview eligible individual recovery prompts. No messages were sent.');
    }
}
