<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Illuminate\Support\Facades\Http;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;

/** Uses the SDK's chat-completions/tool gateway, not its OpenAI Responses gateway. */
class CerebrasAdapter extends GroqAdapter
{
    public function testConnection(AiProviderConnection $connection): array
    {
        try {
            if (!$connection->getApiKey()) {
                throw new \RuntimeException('API key is required.');
            }
            Http::withToken($connection->getApiKey())->timeout(15)
                ->get($connection->getBaseUrl().'/models')->throw();
            return ['success' => true, 'message' => 'Cerebras credentials accepted. Run an inference test before enabling models.', 'latency_ms' => 0];
        } catch (\Throwable) {
            return ['success' => false, 'message' => 'Cerebras credential check failed. Check the key, trial balance and provider status.', 'latency_ms' => 0];
        }
    }

    public function discoverModels(AiProviderConnection $connection): array
    {
        if (!$this->testConnection($connection)['success']) return [];
        $available = Http::withToken($connection->getApiKey())->timeout(15)
            ->get($connection->getBaseUrl().'/models')->throw()->json('data') ?? [];
        $availableIds = array_column($available, 'id');
        // Public metadata supplies current prices and capabilities; it does not authenticate the key.
        $data = Http::timeout(15)->get('https://api.cerebras.ai/public/v1/models', ['format' => 'openrouter'])->throw()->json('data') ?? [];
        $models = [];
        foreach ($data as $item) {
            if (!isset($item['id'], $item['pricing']['prompt'], $item['pricing']['completion'])) continue;
            if (!in_array($item['id'], $availableIds, true)) continue;
            // The public catalogue omits supported_parameters for this documented tool model.
            if ($item['id'] !== 'gpt-oss-120b' && !in_array('tools', $item['supported_parameters'] ?? [], true)) continue;
            $models[] = [
                'id' => $item['id'], 'name' => $item['name'] ?? $item['id'],
                'supports_tool_calling' => true, 'supports_vision' => false,
                // A time-limited trial is not a permanently free model.
                'is_free_tier' => false, 'context_window' => $item['context_length'] ?? 32768,
                'cost_per_million_input' => (float) $item['pricing']['prompt'] * 1000000,
                'cost_per_million_output' => (float) $item['pricing']['completion'] * 1000000,
            ];
        }
        return $models;
    }
}
