<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;
use Laravel\Ai\Responses\AgentResponse;

class AiFallbackService
{
    public function __construct(
        protected ?AiRouterService $router = null
    ) {
        $this->router ??= app(AiRouterService::class);
    }

    /**
     * Get legacy active and working AI providers.
     */
    protected function getProviders()
    {
        return WhatsAppAiProvider::activeAndWorking()->get();
    }

    /**
     * Generate text using the fallback chain of active AI providers via the Agent.
     * Delegates to OmniRoute-style AiRouterService when new connections are configured.
     *
     * @return array{response: AgentResponse, provider: mixed, model: string}
     */
    public function promptAgent(Agent $agent, string $userText): array
    {
        // 1. If modern OmniRoute connections exist and are active, use AiRouterService
        $hasModernConnections = false;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('ai_provider_connections')) {
                $hasModernConnections = AiProviderConnection::where('is_active', true)->exists();
            }
        } catch (\Throwable) {
            $hasModernConnections = false;
        }

        if ($hasModernConnections) {
            $routed = $this->router->routeAndPrompt($agent, $userText);
            return [
                'response' => $routed['response'],
                'provider' => $routed['connection'],
                'model' => is_string($routed['model']) ? $routed['model'] : $routed['model']->model_id,
            ];
        }

        // 2. Legacy fallback path for backwards compatibility
        $providers = $this->getProviders();

        if ($providers->isEmpty()) {
            throw new Exception('No active AI providers configured in the database.');
        }

        foreach ($providers as $provider) {
            $configKey = "ai.providers.{$provider->driver}";
            $originalKey = config("{$configKey}.key");
            $originalBaseUrl = config("{$configKey}.base_url");

            try {
                // Override config safely
                config(["{$configKey}.key" => $provider->api_key]);
                if (!empty($provider->base_url)) {
                    config(["{$configKey}.base_url" => $provider->base_url]);
                }

                // Invoke agent
                $response = $agent->prompt($userText, provider: $provider->driver, model: $provider->model, timeout: 60);

                return [
                    'response' => $response,
                    'provider' => $provider,
                    'model' => $provider->model,
                ];

            } catch (\Throwable $e) {
                Log::warning('AI Provider failed, falling back to next.', [
                    'provider_name' => $provider->name,
                    'driver' => $provider->driver,
                    'error' => $e->getMessage(),
                ]);

                $provider->update([
                    'status' => 'failed',
                    'last_failed_at' => now(),
                ]);
            } finally {
                // Always restore global config safely
                config(["{$configKey}.key" => $originalKey]);
                config(["{$configKey}.base_url" => $originalBaseUrl]);
            }
        }

        throw new Exception('All configured AI providers failed.');
    }
}
