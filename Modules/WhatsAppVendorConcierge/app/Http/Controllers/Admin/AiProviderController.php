<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;

class AiProviderController extends Controller
{
    /**
     * Display a listing of the AI providers.
     */
    public function index()
    {
        $providers = WhatsAppAiProvider::orderBy('priority', 'asc')->get();
        return view('whatsappvendorconcierge::admin.ai_providers.index', compact('providers'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('whatsappvendorconcierge::admin.ai_providers.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|max:50',
            'base_url' => 'nullable|url|max:255',
            'api_key' => 'required|string|max:1000',
            'model' => 'required|string|max:100',
            'priority' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['status'] = 'working';

        WhatsAppAiProvider::create($validated);

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', 'AI Provider created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(WhatsAppAiProvider $aiProvider)
    {
        return view('whatsappvendorconcierge::admin.ai_providers.edit', compact('aiProvider'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WhatsAppAiProvider $aiProvider)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|max:50',
            'base_url' => 'nullable|url|max:255',
            'api_key' => 'nullable|string|max:1000',
            'model' => 'required|string|max:100',
            'priority' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->has('is_active');
        
        if (empty($validated['api_key'])) {
            unset($validated['api_key']);
        }

        // If updated, assume it might be working again
        $validated['status'] = 'working';
        $validated['last_failed_at'] = null;

        $aiProvider->update($validated);

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', 'AI Provider updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WhatsAppAiProvider $aiProvider)
    {
        $aiProvider->delete();

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', 'AI Provider deleted successfully.');
    }

    /**
     * Toggle active status.
     */
    public function toggle(WhatsAppAiProvider $aiProvider)
    {
        $aiProvider->update(['is_active' => !$aiProvider->is_active]);

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', 'AI Provider status updated.');
    }
}
