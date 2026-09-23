<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;

class MigrateLegacyAiProviders extends Command
{
    protected $signature = 'whatsapp:migrate-legacy-ai-providers';

    protected $description = 'Migrate legacy flat whatsapp_ai_providers into OmniRoute connections and models.';

    public function handle(): int
    {
        $this->info('Starting legacy AI provider migration...');

        $legacyProviders = WhatsAppAiProvider::all();
        if ($legacyProviders->isEmpty()) {
            $this->info('No legacy AI providers found. Nothing to migrate.');
            return 0;
        }

        $migratedConnections = 0;
        $migratedModels = 0;

        foreach ($legacyProviders as $legacy) {
            // Determine matching provider definition slug
            $slug = $this->determineDefinitionSlug($legacy);
            $definition = AiProviderDefinition::where('slug', $slug)->first();

            if (!$definition) {
                $definition = AiProviderDefinition::create([
                    'slug' => $slug,
                    'name' => ucfirst($legacy->driver),
                    'adapter_class' => 'Modules\\WhatsAppVendorConcierge\\app\\Services\\AiAdapters\\OpenAiCompatibleAdapter',
                    'default_base_url' => $legacy->base_url,
                    'auth_type' => 'api_key',
                    'supports_model_discovery' => true,
                ]);
            }

            // Create or update connection
            $connectionName = $legacy->name . ' Account';
            $connection = AiProviderConnection::firstOrCreate(
                [
                    'definition_id' => $definition->id,
                    'name' => $connectionName,
                ],
                [
                    'credentials' => [
                        'api_key' => $legacy->api_key,
                    ],
                    'base_url_override' => $legacy->base_url,
                    'is_active' => (bool) $legacy->is_active,
                    'status' => $legacy->status === 'working' ? 'healthy' : 'degraded',
                    'selection_mode' => 'all_compatible',
                ]
            );

            if ($connection->wasRecentlyCreated) {
                $migratedConnections++;
            }

            // Create or update model
            if (!empty($legacy->model)) {
                $model = AiProviderModel::firstOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'model_id' => $legacy->model,
                    ],
                    [
                        'name' => $legacy->name,
                        'is_enabled' => (bool) $legacy->is_active,
                        'supports_tool_calling' => (bool) preg_match('/(gpt-4|o1|o3|llama|deepseek|claude|gemini)/i', $legacy->model),
                        'supports_vision' => (bool) preg_match('/(vision|gpt-4o)/i', $legacy->model),
                        'is_free_tier' => (bool) str_contains(strtolower($legacy->name), 'free') || str_ends_with($legacy->model, ':free'),
                        'priority' => (int) $legacy->priority,
                    ]
                );

                if ($model->wasRecentlyCreated) {
                    $migratedModels++;
                }
            }
        }

        $this->info("Migration completed: {$migratedConnections} new connections, {$migratedModels} new models created.");
        return 0;
    }

    protected function determineDefinitionSlug(WhatsAppAiProvider $legacy): string
    {
        $driver = strtolower($legacy->driver ?? '');
        $url = strtolower($legacy->base_url ?? '');
        $name = strtolower($legacy->name ?? '');

        if (str_contains($url, 'groq') || str_contains($name, 'groq')) return 'groq';
        if (str_contains($url, 'deepseek') || str_contains($name, 'deepseek')) return 'deepseek';
        if (str_contains($url, 'generativelanguage') || str_contains($name, 'gemini')) return 'gemini';
        if (str_contains($url, 'openrouter') || str_contains($name, 'openrouter')) return 'openrouter';
        if (str_contains($url, 'nvidia') || str_contains($name, 'nvidia')) return 'nvidia_nim';
        if ($driver === 'anthropic' || str_contains($name, 'claude')) return 'anthropic';

        return 'openai';
    }
}
