from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
def put(p,s): (r/p).write_text(s,encoding='utf-8')
def edit(p,a,b):
 f=r/p;s=f.read_text(encoding='utf-8');assert a in s,p;f.write_text(s.replace(a,b),encoding='utf-8')
put('app/Services/AiAdapters/CerebrasAdapter.php',r'''<?php

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
        // Public metadata supplies current prices and capabilities; it does not authenticate the key.
        $data = Http::timeout(15)->get('https://api.cerebras.ai/public/v1/models', ['format' => 'openrouter'])->throw()->json('data') ?? [];
        $models = [];
        foreach ($data as $item) {
            if (!isset($item['id'], $item['pricing']['prompt'], $item['pricing']['completion'])) continue;
            if (!in_array('tools', $item['supported_parameters'] ?? [], true)) continue;
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
''')
put('app/Services/AiAdapters/CloudflareWorkersAiAdapter.php',r'''<?php

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
''')
put('app/Services/AiAdapters/OpenRouterFreeAdapter.php',r'''<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Laravel\Ai\Contracts\Agent;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

class OpenRouterFreeAdapter extends OpenRouterAdapter
{
    public function discoverModels(AiProviderConnection $connection): array
    {
        if (!$this->testConnection($connection)['success']) return [];
        return array_values(array_filter(parent::discoverModels($connection), fn (array $model) =>
            $this->freeId($model['id']) && $model['is_free_tier'] &&
            $model['cost_per_million_input'] == 0 && $model['cost_per_million_output'] == 0 &&
            $model['supports_tool_calling']
        ));
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
''')
edit('app/Models/AiProviderConnection.php',"return $this->base_url_override ?: $this->definition?->default_base_url;",r'''// These integrations always use official hosts, including after credential rotation.
        if ($this->definition?->slug === 'cloudflare_workers_ai') {
            $account = $this->credentials['account_id'] ?? '';
            if (!preg_match('/\A[a-f0-9]{32}\z/i', $account)) {
                throw new \InvalidArgumentException('A valid Cloudflare account ID is required.');
            }
            return "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/v1";
        }
        if ($this->definition?->slug === 'cerebras') return 'https://api.cerebras.ai/v1';
        if ($this->definition?->slug === 'openrouter_free') return 'https://openrouter.ai/api/v1';
        return $this->base_url_override ?: $this->definition?->default_base_url;''')
edit('app/Services/AiAdapters/AdapterFactory.php',"'groq' => app(GroqAdapter::class),","'groq' => app(GroqAdapter::class),\n            'cerebras' => app(CerebrasAdapter::class),\n            'cloudflare_workers_ai' => app(CloudflareWorkersAiAdapter::class),\n            'openrouter_free' => app(OpenRouterFreeAdapter::class),")
p=r/'app/Services/AiAdapters/OpenRouterAdapter.php';s=p.read_text();start=s.index('            // Fallback to checking');end=s.index('            return [',s.index('\n            }',start)+14);s=s[:start]+s[end:];s=s.replace('/auth/key','/key').replace("str_ends_with($id, ':free') || ($promptPricing == 0.0 && $completionPricing == 0.0)","($promptPricing == 0.0 && $completionPricing == 0.0)").replace("in_array('tools', $supportedParams, true) || (bool) preg_match('/(llama-3|gpt-4|claude|gemini|mistral|deepseek)/i', $id)","in_array('tools', $supportedParams, true)");p.write_text(s)
put('database/seeders/AdditionalAiProviderSeeder.php',r'''<?php

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
''')
put('database/migrations/2026_09_27_120000_add_optional_ai_providers.php',r'''<?php

use Illuminate\Database\Migrations\Migration;
use Modules\WhatsAppVendorConcierge\database\seeders\AdditionalAiProviderSeeder;

return new class extends Migration {
    public function up(): void
    {
        (new AdditionalAiProviderSeeder)->run();
    }

    public function down(): void
    {
        // Preserve definitions, encrypted credentials and usage history on rollback.
    }
};
''')
edit('database/seeders/AiProviderDefinitionSeeder.php','        $definitions = [','        $this->call(AdditionalAiProviderSeeder::class);\n\n        $definitions = [')
