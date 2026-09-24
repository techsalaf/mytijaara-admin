<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;
use Illuminate\Support\Facades\DB;

class AiDashboardController extends Controller
{
    public function index(): View
    {
        // 1. Overview metrics
        $modernConnections = AiProviderConnection::with('models')->get();
        $legacyProviders = WhatsAppAiProvider::all();
        
        $totalDailyCost = $modernConnections->sum('current_day_cost_usd');
        $activeRoutesCount = $modernConnections->where('is_active', true)
            ->whereIn('status', ['inference_verified', 'models_discovered', 'authenticating'])
            ->count();

        // 2. Fallback health
        $legacyActive = $legacyProviders->where('is_active', true)->whereNotNull('api_key')->count();

        return view('whatsappvendorconcierge::admin.ai_dashboard.index', compact(
            'modernConnections',
            'legacyProviders',
            'totalDailyCost',
            'activeRoutesCount',
            'legacyActive'
        ));
    }
}
