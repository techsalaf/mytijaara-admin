<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Laravel\Ai\Contracts\Agent;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

class OpenRouterFreeAdapter extends OpenRouterAdapter
{
    public function discoverModels(AiProviderConnection $connection): array
    {
        if (!$this->testConnection($connection)['success']) return [];
        $models = array_values(array_filter(parent::discoverModels($connection), fn (array $model) =>
            $this->freeId($model['id']) && $model['is_free_tier'] &&
            $model['cost_per_million_input'] == 0 && $model['cost_per_million_output'] == 0 &&
            $model['supports_tool_calling']
        ));
        // This integration currently transports text and tools, not image attachments.
        return array_map(fn (array $model) => array_merge($model, ['supports_vision' => false]), $models);
    }

    public function invokeAgent(AiProviderConnection $connection, AiProviderModel $model, Agent $agent, string $prompt, array $options = []): array
    {
        if (!$this->freeId($model->model_id) || !$model->is_free_tier ||
            (float) $model->cost_per_million_input !== 0.0 || (float) $model->cost_per_million_output !== 0.0) {
            throw new \RuntimeException('This connection only permits verified OpenRouter free models.');
        }
        return parent::invokeAgent($connection, $model, $agent, $prompt, $options);
    }

    private function freeId(string $id): bool
    {
        return $id === 'openrouter/free' || str_ends_with($id, ':free');
    }
}
