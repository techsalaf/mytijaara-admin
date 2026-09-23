<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

class ModelDiscoveryService
{
    /**
     * Synchronise models for an AI connection.
     *
     * @return array{synced: int, new: int, models: Collection<int, AiProviderModel>}
     */
    public function syncConnectionModels(AiProviderConnection $connection): array
    {
        $definition = $connection->definition;
        $adapter = AdapterFactory::forConnection($connection);

        $discovered = [];

        // 1. Attempt API discovery if supported
        if ($definition?->supports_model_discovery) {
            try {
                $discovered = $adapter->discoverModels($connection);
            } catch (\Throwable $e) {
                Log::warning('Model discovery failed via API, falling back to catalogue', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. If API discovery yielded nothing, fallback to catalogue models from definition
        if (empty($discovered) && !empty($definition?->catalogue_models)) {
            $discovered = $this->transformCatalogueModels($definition->catalogue_models);
        }

        $syncedCount = 0;
        $newCount = 0;
        $syncedModels = collect();

        foreach ($discovered as $item) {
            $modelId = $item['id'];
            $existing = AiProviderModel::where('connection_id', $connection->id)
                ->where('model_id', $modelId)
                ->first();

            $isNew = !$existing;
            $isEnabled = $this->determineEnabledStatus($connection, $item, $existing);

            // Determine priority: free models get priority 1 by default, paid get priority 10
            $defaultPriority = ($item['is_free_tier'] ?? false) ? 1 : 10;
            $priority = $existing?->priority ?? $defaultPriority;

            $model = AiProviderModel::updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'model_id' => $modelId,
                ],
                [
                    'name' => $item['name'] ?? $modelId,
                    'is_enabled' => $isEnabled,
                    'supports_tool_calling' => (bool) ($item['supports_tool_calling'] ?? false),
                    'supports_vision' => (bool) ($item['supports_vision'] ?? false),
                    'is_free_tier' => (bool) ($item['is_free_tier'] ?? false),
                    'context_window' => $item['context_window'] ?? 16384,
                    'cost_per_million_input' => $item['cost_per_million_input'] ?? 0.0,
                    'cost_per_million_output' => $item['cost_per_million_output'] ?? 0.0,
                    'priority' => $priority,
                ]
            );

            $syncedModels->push($model);
            $syncedCount++;
            if ($isNew) {
                $newCount++;
            }
        }

        $connection->update([
            'last_tested_at' => now(),
            'status' => 'healthy',
            'last_error' => null,
        ]);

        return [
            'synced' => $syncedCount,
            'new' => $newCount,
            'models' => $syncedModels,
        ];
    }

    /**
     * Determine initial or updated is_enabled status based on connection mode.
     */
    protected function determineEnabledStatus(
        AiProviderConnection $connection,
        array $item,
        ?AiProviderModel $existing
    ): bool {
        // If already configured by admin in manual mode, preserve existing choice
        if ($existing !== null && $connection->selection_mode === 'manual') {
            return $existing->is_enabled;
        }

        $isFree = $item['is_free_tier'] ?? false;

        return match ($connection->selection_mode) {
            'all_compatible' => true,
            'auto_include_free' => (bool) $isFree,
            'manual' => $existing ? $existing->is_enabled : false,
            default => true,
        };
    }

    /**
     * Transform definition catalogue models into discovery format.
     */
    protected function transformCatalogueModels(array $catalogue): array
    {
        $transformed = [];
        foreach ($catalogue as $cat) {
            $transformed[] = [
                'id' => $cat['id'],
                'name' => $cat['name'] ?? $cat['id'],
                'supports_tool_calling' => (bool) ($cat['tools'] ?? false),
                'supports_vision' => (bool) ($cat['vision'] ?? false),
                'is_free_tier' => (bool) ($cat['free'] ?? false),
                'context_window' => $cat['context'] ?? 16384,
                'cost_per_million_input' => 0.0,
                'cost_per_million_output' => 0.0,
            ];
        }
        return $transformed;
    }
}
