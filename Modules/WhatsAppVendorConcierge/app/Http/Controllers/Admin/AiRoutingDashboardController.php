<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\WhatsAppVendorConcierge\app\Agents\VendorAiContext;
use Modules\WhatsAppVendorConcierge\app\Agents\VendorConciergeAgent;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingAttempt;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingPolicy;
use Modules\WhatsAppVendorConcierge\app\Models\AiUsageRecord;
use Modules\WhatsAppVendorConcierge\app\Services\AiRouterService;

class AiRoutingDashboardController extends Controller
{
    public function __construct(
        protected AiRouterService $routerService
    ) {}

    /**
     * Display the AI Routing Dashboard.
     */
    public function index(): View
    {
        // Ensure default policy exists
        $policies = AiRoutingPolicy::all();
        if ($policies->isEmpty()) {
            AiRoutingPolicy::create([
                'name' => 'Default Concierge Policy',
                'slug' => 'default_concierge',
                'strategy' => 'free_first',
                'requires_tool_calling' => true,
                'is_active' => true,
            ]);
            $policies = AiRoutingPolicy::all();
        }

        $today = now()->toDateString();
        $todayUsage = AiUsageRecord::where('usage_date', $today)->get();

        $stats = [
            'total_requests' => $todayUsage->sum('total_requests'),
            'successful_requests' => $todayUsage->sum('successful_requests'),
            'failed_requests' => $todayUsage->sum('failed_requests'),
            'total_tokens' => $todayUsage->sum('total_prompt_tokens') + $todayUsage->sum('total_completion_tokens'),
            'total_cost_usd' => $todayUsage->sum('total_cost_usd'),
        ];

        $recentAttempts = AiRoutingAttempt::with(['connection', 'model'])
            ->latest('created_at')
            ->limit(20)
            ->get();

        $availableModels = AiProviderModel::with('connection')
            ->where('is_enabled', true)
            ->get();

        return view('whatsapp-vendor-concierge::admin.ai_routing.index', compact('policies', 'stats', 'recentAttempts', 'availableModels'));
    }

    /**
     * Update a routing policy's strategy and rules.
     */
    public function updatePolicy(Request $request, AiRoutingPolicy $policy): RedirectResponse
    {
        $validated = $request->validate([
            'strategy' => 'required|in:free_first,strict_fallback,round_robin,weighted,lowest_cost,quality_first',
            'requires_tool_calling' => 'nullable|boolean',
            'requires_vision' => 'nullable|boolean',
            'max_latency_ms' => 'nullable|integer|min:0',
            'max_cost_per_turn_usd' => 'nullable|numeric|min:0',
        ]);

        $policy->update([
            'strategy' => $validated['strategy'],
            'requires_tool_calling' => $request->boolean('requires_tool_calling'),
            'requires_vision' => $request->boolean('requires_vision'),
            'max_latency_ms' => $validated['max_latency_ms'] ?? null,
            'max_cost_per_turn_usd' => $validated['max_cost_per_turn_usd'] ?? null,
        ]);

        return redirect()->route('admin.whatsapp.ai-routing.index')
            ->with('success', "Routing policy {$policy->name} updated successfully.");
    }

    /**
     * Simulate prompt routing live.
     */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:1000',
            'policy' => 'nullable|string',
            'simulate_tools' => 'nullable|boolean',
        ]);

        $start = microtime(true);

        try {
            // Instantiate simulated agent context
            $context = new VendorAiContext(contactId: 0, conversationId: 0);
            $agent = new VendorConciergeAgent(
                context: $context,
                history: [],
                vendor: null,
                store: null,
                language: 'en'
            );

            $result = $this->routerService->routeAndPrompt(
                agent: $agent,
                prompt: $validated['prompt'],
                options: [
                    'policy' => $validated['policy'] ?? 'default_concierge',
                ]
            );

            $latency = (int) round((microtime(true) - $start) * 1000);

            return response()->json([
                'success' => true,
                'model' => $result['model']->model_id,
                'model_name' => $result['model']->name,
                'is_free' => $result['model']->is_free_tier,
                'connection' => $result['connection']->name,
                'latency_ms' => $latency,
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'cost_usd' => $result['cost_usd'],
                'response_text' => (string) $result['response']->text,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ], 422);
        }
    }
}
