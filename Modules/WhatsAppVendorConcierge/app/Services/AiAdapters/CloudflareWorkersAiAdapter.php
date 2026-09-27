<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

class CloudflareWorkersAiAdapter extends GroqAdapter
{
    public const MODEL = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

    public function testConnection(AiProviderConnection $connection): array
    {
        try {
            $models = $this->discoverModels($connection);
            return ['success' => count($models) > 0, 'message' => count($models) ? 'Account and model access verified. Run an inference test to verify Workers AI Write permission.' : 'No supported Workers AI model is available.', 'latency_ms' => 0];
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'Cloudflare check failed. Check account ID, token permissions and account quota.', 'latency_ms' => 0];
        }
    }

    public function discoverModels(AiProviderConnection $connection): array
    {
        if (!$connection->getApiKey()) return [];
        $url = substr($connection->getBaseUrl(), 0, -3).'/models/search';
        $response = Http::withToken($connection->getApiKey())->timeout(15)
            ->get($url, ['search' => self::MODEL, 'per_page' => 100])->throw();
        if ($response->json('success') !== true) return [];
        foreach ($response->json('result') ?? [] as $item) {
            if (($item['name'] ?? '') !== self::MODEL) continue;
            // Deliberately restricted to a documented function-calling model.
            return [[
                'id' => self::MODEL, 'name' => 'Llama 3.3 70B (Workers AI daily allowance)',
                'supports_tool_calling' => true, 'supports_vision' => false,
                'is_free_tier' => true, 'context_window' => 24000,
                'cost_per_million_input' => 0, 'cost_per_million_output' => 0,
            ]];
        }
        return [];
    }

    public function invokeAgent(AiProviderConnection $connection, AiProviderModel $model, Agent $agent, string $prompt, array $options = []): array
    {
        if ($model->model_id !== self::MODEL || empty($connection->credentials['free_plan_confirmed'])) {
            throw new \RuntimeException('Confirm Workers Free plan and select the supported model before enabling this connection.');
        }
        return parent::invokeAgent($connection, $model, $agent, $prompt, $options);
    }
}
