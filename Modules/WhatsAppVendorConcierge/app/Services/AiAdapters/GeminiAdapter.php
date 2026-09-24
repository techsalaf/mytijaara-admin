<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Prompts\AgentPrompt;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

class GeminiAdapter extends BaseProviderAdapter
{
    public function supportsCapability(string $capability): bool
    {
        return match ($capability) {
            'tools', 'vision', 'streaming', 'discovery' => true,
            default => false,
        };
    }

    public function testConnection(AiProviderConnection $connection): array
    {
        $start = microtime(true);
        $baseUrl = rtrim($connection->getBaseUrl() ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $apiKey = $connection->getApiKey();

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => 'API key is missing or empty.',
                'latency_ms' => 0,
            ];
        }

        try {
            $response = Http::timeout(10)->get("{$baseUrl}/models?key={$apiKey}");

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $count = count($response->json('models') ?? []);
                return [
                    'success' => true,
                    'message' => "Gemini connection successful ({$count} models found).",
                    'latency_ms' => $latency,
                ];
            }

            return [
                'success' => false,
                'message' => "HTTP {$response->status()}: " . ($response->json('error.message') ?? $response->body()),
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    public function discoverModels(AiProviderConnection $connection): array
    {
        $baseUrl = rtrim($connection->getBaseUrl() ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $apiKey = $connection->getApiKey();

        if (empty($apiKey)) {
            return [];
        }

        try {
            $response = Http::timeout(15)->get("{$baseUrl}/models?key={$apiKey}");

            if (!$response->successful()) {
                return [];
            }

            $rawModels = $response->json('models') ?? [];
            $discovered = [];

            foreach ($rawModels as $item) {
                $rawName = $item['name'] ?? ''; // e.g. "models/gemini-1.5-flash"
                $id = str_replace('models/', '', $rawName);
                if (empty($id) || !str_contains($id, 'gemini')) continue;

                $supportsTools = true;
                $supportsVision = true;
                $isFree = true; // Gemini has generous free tier for developers
                $context = (int) ($item['inputTokenLimit'] ?? 1048576);

                $discovered[] = [
                    'id' => $id,
                    'name' => $item['displayName'] ?? ucwords(str_replace(['-', '_'], ' ', $id)),
                    'supports_tool_calling' => $supportsTools,
                    'supports_vision' => $supportsVision,
                    'is_free_tier' => $isFree,
                    'context_window' => $context,
                    'cost_per_million_input' => 0.0,
                    'cost_per_million_output' => 0.0,
                ];
            }

            return $discovered;
        } catch (\Throwable) {
            return [];
        }
    }

    public function invokeAgent(
        AiProviderConnection $connection,
        AiProviderModel $model,
        Agent $agent,
        string $prompt,
        array $options = []
    ): array {
        $start = microtime(true);
        $aiManager = app(AiManager::class);

        $driver = $aiManager->createGeminiDriver([
            'driver' => 'gemini',
            'name' => 'gemini',
            'key' => $connection->getApiKey(),
            'url' => $connection->getBaseUrl(),
        ]);

        if (Ai::hasFakeGatewayFor($agent::class)) {
            $driver->useTextGateway(Ai::fakeGatewayFor($agent::class));
        }

        $timeout = $options['timeout'] ?? 60;
        $agentPrompt = new AgentPrompt($agent, $prompt, [], $driver, $model->model_id, $timeout);

        $response = $driver->prompt($agentPrompt);

        $latency = (int) round((microtime(true) - $start) * 1000);
        $usage = $this->extractUsage($response);
        $cost = $this->calculateCost($model, $usage['prompt_tokens'], $usage['completion_tokens']);

        return [
            'response' => $response,
            'latency_ms' => $latency,
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'cost_usd' => $cost,
        ];
    }
}
