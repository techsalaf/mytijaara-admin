<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Prompts\AgentPrompt;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

class OpenRouterAdapter extends BaseProviderAdapter
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
        $baseUrl = rtrim($connection->getBaseUrl() ?: 'https://openrouter.ai/api/v1', '/');
        $apiKey = $connection->getApiKey();

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => 'OpenRouter API key is missing or empty.',
                'latency_ms' => 0,
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'https://mytijaara.com'),
                    'X-Title' => 'MyTijaara WhatsApp Concierge',
                ])
                ->timeout(10)
                ->get("{$baseUrl}/auth/key");

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $label = $response->json('data.label') ?? 'Key verified';
                $usage = $response->json('data.usage') ?? 0;
                return [
                    'success' => true,
                    'message' => "OpenRouter verified: {$label} (Usage: \${$usage})",
                    'latency_ms' => $latency,
                ];
            }

            // Fallback to checking models if /auth/key isn't supported
            $modelsResp = Http::withToken($apiKey)->timeout(10)->get("{$baseUrl}/models");
            if ($modelsResp->successful()) {
                return [
                    'success' => true,
                    'message' => "OpenRouter connection verified.",
                    'latency_ms' => $latency,
                ];
            }

            return [
                'success' => false,
                'message' => "OpenRouter error: " . ($response->json('error.message') ?? $response->body()),
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
        $baseUrl = rtrim($connection->getBaseUrl() ?: 'https://openrouter.ai/api/v1', '/');
        $apiKey = $connection->getApiKey();

        if (empty($apiKey)) {
            return [];
        }

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'https://mytijaara.com'),
                    'X-Title' => 'MyTijaara WhatsApp Concierge',
                ])
                ->timeout(15)
                ->get("{$baseUrl}/models");

            if (!$response->successful()) {
                return [];
            }

            $rawModels = $response->json('data') ?? [];
            $discovered = [];

            foreach ($rawModels as $item) {
                $id = $item['id'] ?? '';
                if (empty($id)) continue;

                $promptPricing = (float) ($item['pricing']['prompt'] ?? 1);
                $completionPricing = (float) ($item['pricing']['completion'] ?? 1);
                $isFree = str_ends_with($id, ':free') || ($promptPricing == 0.0 && $completionPricing == 0.0);

                $supportedParams = $item['supported_parameters'] ?? [];
                $supportsTools = in_array('tools', $supportedParams, true) || (bool) preg_match('/(llama-3|gpt-4|claude|gemini|mistral|deepseek)/i', $id);
                $architecture = $item['architecture']['modality'] ?? '';
                $supportsVision = str_contains($architecture, 'image') || str_contains($id, 'vision');

                $discovered[] = [
                    'id' => $id,
                    'name' => $item['name'] ?? ucwords(str_replace(['-', '_', '/'], ' ', $id)),
                    'supports_tool_calling' => $supportsTools,
                    'supports_vision' => $supportsVision,
                    'is_free_tier' => $isFree,
                    'context_window' => (int) ($item['context_length'] ?? 128000),
                    'cost_per_million_input' => round($promptPricing * 1_000_000, 4),
                    'cost_per_million_output' => round($completionPricing * 1_000_000, 4),
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

        $driver = $aiManager->createOpenrouterDriver([
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
