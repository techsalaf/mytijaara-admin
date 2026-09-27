<?php

namespace Modules\WhatsAppVendorConcierge\database\seeders;

use Illuminate\Database\Seeder;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderDefinition;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\{CerebrasAdapter, CloudflareWorkersAiAdapter, OpenRouterFreeAdapter};

class AdditionalAiProviderSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['cerebras', 'Cerebras (limited trial / paid)', CerebrasAdapter::class, 'https://api.cerebras.ai/v1', 'https://inference-docs.cerebras.ai/support/rate-limits'],
            ['cloudflare_workers_ai', 'Cloudflare Workers AI (daily free allowance)', CloudflareWorkersAiAdapter::class, 'https://api.cloudflare.com/client/v4', 'https://developers.cloudflare.com/workers-ai/platform/pricing/'],
            ['openrouter_free', 'OpenRouter (free models only)', OpenRouterFreeAdapter::class, 'https://openrouter.ai/api/v1', 'https://openrouter.ai/pricing/'],
        ] as [$slug, $name, $adapter, $url, $docs]) {
            AiProviderDefinition::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'adapter_class' => $adapter, 'default_base_url' => $url,
                'documentation_url' => $docs, 'auth_type' => 'api_key',
                'supports_model_discovery' => true, 'supports_tool_calling' => true,
                'supports_vision' => false, 'supports_streaming' => true,
                'catalogue_models' => [], 'is_active' => true,
            ]);
        }
    }
}
