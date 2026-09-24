<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Prompts\AgentPrompt;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

class DeepSeekAdapter extends BaseProviderAdapter
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
        $baseUrl = rtrim($connection->getBaseUrl() ?: 'https://api.deepseek.com', '/');
        $apiKey = $connection->getApiKey();

        if (empty($apiKey)) {
            return [
                'success' => false,
                'message' => 'API key is missing or empty.',
                'latency_ms' => 0,
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get("{$baseUrl}/models");

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $count = count($response->json('data') ?? []);
                return [
                    'success' => true,
                    'message' => "Connection successful ({$count} models available).",
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
        $baseUrl = rtrim($connection->getBaseUrl() ?: 'https://api.deepseek.com', '/');
        $apiKey = $connection->getApiKey();

        if (empty($apiKey)) {
            return [];
        }

        try {
            $response = Http::withToken($apiKey)
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

                // Exclude embedding, audio, tts, moderation, image generators
                if (preg_match('/(embedding|whisper|tts|moderation|dall-e|realtime)/i', $id)) {
                    continue;
                }

                $supportsTools = (bool) preg_match('/(gpt-4|gpt-3\.5-turbo|o1|o3|llama|deepseek|mistral|claude|qwen)/i', $id);
                $supportsVision = (bool) preg_match('/(vision|gpt-4o|gpt-4-turbo|gemini)/i', $id);
                $isFree = (bool) str_ends_with($id, ':free');

                $discovered[] = [
                    'id' => $id,
                    'name' => ucwords(str_replace(['-', '_', '/'], ' ', $id)),
                    'supports_tool_calling' => $supportsTools,
                    'supports_vision' => $supportsVision,
                    'is_free_tier' => $isFree,
                    'context_window' => str_contains($id, '128k') ? 128000 : 16384,
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

        $driver = $aiManager->createDeepseekDriver([
            'driver' => 'deepseek',
            'name' => 'deepseek',
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
