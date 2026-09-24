<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

class AiDiagnosticsController extends Controller
{
    /**
     * Test 1: Credential / Authentication test
     */
    public function testCredentials(AiProviderConnection $aiProvider): JsonResponse
    {
        $adapter = AdapterFactory::forConnection($aiProvider);
        $result = $adapter->testConnection($aiProvider);

        if ($result['success']) {
            $aiProvider->update([
                'status' => 'authenticating',
                'last_tested_at' => now(),
                'last_error' => null,
            ]);
        } else {
            $aiProvider->update([
                'status' => 'authentication_failed',
                'last_tested_at' => now(),
                'last_error' => $result['message'],
            ]);
        }

        return response()->json($result);
    }

    /**
     * Test 2: Model-discovery test
     */
    public function testDiscovery(AiProviderConnection $aiProvider): JsonResponse
    {
        $adapter = AdapterFactory::forConnection($aiProvider);
        
        $start = microtime(true);
        $models = $adapter->discoverModels($aiProvider);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if (!empty($models)) {
            $aiProvider->update([
                'status' => 'models_discovered',
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Discovered " . count($models) . " models successfully.",
                'models' => $models,
                'latency_ms' => $latency,
            ]);
        }

        $aiProvider->update([
            'status' => 'discovery_failed',
            'last_tested_at' => now(),
            'last_error' => 'Failed to discover models or zero models returned.',
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to discover models from provider.',
            'latency_ms' => $latency,
        ], 400);
    }

    /**
     * Test 3/4: Real text-generation test (Per-model)
     */
    public function testInference(Request $request, AiProviderConnection $aiProvider): JsonResponse
    {
        $request->validate([
            'model_id' => 'required|string',
        ]);

        $modelId = $request->input('model_id');
        $model = $aiProvider->models()->where('model_id', $modelId)->first();
        if (!$model) {
            // Test with an ad-hoc model object if it's not saved yet, but normally we test saved models
            $model = new AiProviderModel(['model_id' => $modelId]);
        }

        $adapter = AdapterFactory::forConnection($aiProvider);
        
        try {
            $agent = new \Laravel\Ai\Agents\SystemAgent("You are a helpful assistant.");
            $result = $adapter->invokeAgent(
                $aiProvider,
                $model,
                $agent,
                "Respond with exactly one word: 'OK'."
            );

            $text = trim((string) $result['response']->text);
            $success = stripos($text, 'OK') !== false || strlen($text) > 0;

            if ($success) {
                $aiProvider->update(['status' => 'inference_verified', 'last_tested_at' => now(), 'last_error' => null]);
            } else {
                $aiProvider->update(['status' => 'degraded', 'last_tested_at' => now(), 'last_error' => "Model returned empty or invalid response: {$text}"]);
            }

            return response()->json([
                'success' => $success,
                'message' => $success ? "Inference successful." : "Inference returned unexpected output.",
                'response_text' => $text,
                'latency_ms' => $result['latency_ms'],
                'usage' => [
                    'prompt_tokens' => $result['prompt_tokens'],
                    'completion_tokens' => $result['completion_tokens'],
                    'cost_usd' => $result['cost_usd']
                ]
            ]);

        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $aiProvider->update([
                'status' => 'degraded',
                'last_tested_at' => now(),
                'last_error' => "Inference failed: {$error}",
            ]);

            return response()->json([
                'success' => false,
                'message' => "Inference failed.",
                'error' => $error,
            ], 500);
        }
    }
}
