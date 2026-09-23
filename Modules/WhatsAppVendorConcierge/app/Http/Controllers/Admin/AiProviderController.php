<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;
use Modules\WhatsAppVendorConcierge\app\Services\ModelDiscoveryService;

class AiProviderController extends Controller
{
    public function __construct(
        protected ModelDiscoveryService $discoveryService
    ) {}

    /**
     * Display a listing of connected AI accounts and discovered models.
     */
    public function index(): View
    {
        $connections = AiProviderConnection::with(['definition', 'models' => function ($q) {
            $q->orderBy('priority', 'asc')->orderBy('is_free_tier', 'desc');
        }])->latest()->get();

        $definitions = AiProviderDefinition::where('is_active', true)->orderBy('name', 'asc')->get();

        return view('whatsapp-vendor-concierge::admin.ai_providers.index', compact('connections', 'definitions'));
    }

    /**
     * Show the form for connecting a new AI provider account.
     */
    public function create(Request $request): View
    {
        $selectedDefinition = null;
        if ($request->has('definition_id')) {
            $selectedDefinition = AiProviderDefinition::find($request->get('definition_id'));
        }

        $definitions = AiProviderDefinition::where('is_active', true)->orderBy('name', 'asc')->get();

        return view('whatsapp-vendor-concierge::admin.ai_providers.create', compact('definitions', 'selectedDefinition'));
    }

    /**
     * Store a newly connected AI provider account and discover its models.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'definition_id' => 'required|exists:ai_provider_definitions,id',
            'name' => 'required|string|max:255',
            'api_key' => 'required|string|max:1000',
            'base_url_override' => 'nullable|url|max:255',
            'selection_mode' => 'required|in:all_compatible,auto_include_free,manual',
            'daily_budget_usd' => 'nullable|numeric|min:0',
            'monthly_budget_usd' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $connection = AiProviderConnection::create([
            'definition_id' => $validated['definition_id'],
            'name' => $validated['name'],
            'credentials' => [
                'api_key' => trim($validated['api_key']),
            ],
            'base_url_override' => $validated['base_url_override'] ?? null,
            'selection_mode' => $validated['selection_mode'],
            'daily_budget_usd' => $validated['daily_budget_usd'] ?? null,
            'monthly_budget_usd' => $validated['monthly_budget_usd'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'status' => 'healthy',
        ]);

        // Auto-discover models immediately upon connection
        $syncResult = $this->discoveryService->syncConnectionModels($connection);

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', "Connected {$connection->name} successfully. Discovered {$syncResult['synced']} models.");
    }

    /**
     * Show the form for editing an existing connected AI account.
     */
    public function edit(AiProviderConnection $aiProvider): View
    {
        $connection = $aiProvider->load(['definition', 'models']);
        $definitions = AiProviderDefinition::where('is_active', true)->orderBy('name', 'asc')->get();

        return view('whatsapp-vendor-concierge::admin.ai_providers.edit', compact('connection', 'definitions'));
    }

    /**
     * Update the connection settings and optionally update the credentials.
     */
    public function update(Request $request, AiProviderConnection $aiProvider): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'api_key' => 'nullable|string|max:1000',
            'base_url_override' => 'nullable|url|max:255',
            'selection_mode' => 'required|in:all_compatible,auto_include_free,manual',
            'daily_budget_usd' => 'nullable|numeric|min:0',
            'monthly_budget_usd' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $updates = [
            'name' => $validated['name'],
            'base_url_override' => $validated['base_url_override'] ?? null,
            'selection_mode' => $validated['selection_mode'],
            'daily_budget_usd' => $validated['daily_budget_usd'] ?? null,
            'monthly_budget_usd' => $validated['monthly_budget_usd'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        if (!empty($validated['api_key'])) {
            $updates['credentials'] = [
                'api_key' => trim($validated['api_key']),
            ];
            $updates['status'] = 'healthy';
            $updates['consecutive_failures'] = 0;
            $updates['last_error'] = null;
        }

        $aiProvider->update($updates);

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', "Updated {$aiProvider->name} successfully.");
    }

    /**
     * Remove the connection and associated models.
     */
    public function destroy(AiProviderConnection $aiProvider): RedirectResponse
    {
        $name = $aiProvider->name;
        $aiProvider->delete();

        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', "Connection {$name} removed.");
    }

    /**
     * Toggle the active status of a connection.
     */
    public function toggle(AiProviderConnection $aiProvider): RedirectResponse
    {
        $aiProvider->update(['is_active' => !$aiProvider->is_active]);

        $status = $aiProvider->is_active ? 'enabled' : 'disabled';
        return redirect()->route('admin.whatsapp.ai-providers.index')
            ->with('success', "Connection {$aiProvider->name} {$status}.");
    }

    /**
     * Test connection credentials with live provider API.
     */
    public function test(AiProviderConnection $aiProvider): JsonResponse
    {
        $adapter = AdapterFactory::forConnection($aiProvider);
        $result = $adapter->testConnection($aiProvider);

        if ($result['success']) {
            $aiProvider->update([
                'last_tested_at' => now(),
                'status' => 'healthy',
                'last_error' => null,
            ]);
        } else {
            $aiProvider->update([
                'last_tested_at' => now(),
                'last_error' => $result['message'],
            ]);
        }

        return response()->json($result);
    }

    /**
     * Trigger model synchronization / discovery for a connection.
     */
    public function syncModels(AiProviderConnection $aiProvider): JsonResponse
    {
        try {
            $result = $this->discoveryService->syncConnectionModels($aiProvider);
            return response()->json([
                'success' => true,
                'message' => "Successfully synced {$result['synced']} models ({$result['new']} new).",
                'synced' => $result['synced'],
                'new' => $result['new'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle individual model enabled/disabled.
     */
    public function toggleModel(AiProviderModel $model): JsonResponse
    {
        $model->update(['is_enabled' => !$model->is_enabled]);

        return response()->json([
            'success' => true,
            'is_enabled' => $model->is_enabled,
            'message' => "Model {$model->model_id} is now " . ($model->is_enabled ? 'enabled' : 'disabled') . '.',
        ]);
    }

    /**
     * Update individual model priority and weight.
     */
    public function updateModel(Request $request, AiProviderModel $model): JsonResponse
    {
        $validated = $request->validate([
            'priority' => 'nullable|integer|min:0|max:1000',
            'weight' => 'nullable|integer|min:1|max:1000',
        ]);

        $model->update($validated);

        return response()->json([
            'success' => true,
            'message' => "Model settings updated.",
        ]);
    }
}
