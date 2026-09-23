<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiProvider;
use Laravel\Ai\Responses\AgentResponse;

class AiFallbackService
{
    /**
     * Get active and working AI providers.
     */
    protected function getProviders()
    {
        return WhatsAppAiProvider::activeAndWorking()->get();
    }

    /**
     * Generate text using the fallback chain of active AI providers via the Agent.
     * @return array{response: AgentResponse, provider: WhatsAppAiProvider, model: string}
     */
    public function promptAgent(Agent $agent, string $userText): array
    {
        $providers = $this->getProviders();

        if ($providers->isEmpty()) {
            throw new Exception('No active AI providers configured in the database.');
        }

        foreach ($providers as $provider) {
            try {
                $configKey = "ai.providers.{$provider->driver}";
                $originalKey = config("{$configKey}.key");
                $originalBaseUrl = config("{$configKey}.base_url");

                // Override config
                config(["{$configKey}.key" => $provider->api_key]);
                if (!empty($provider->base_url)) {
                    config(["{$configKey}.base_url" => $provider->base_url]);
                }

                // Invoke agent
                $response = $agent->prompt($userText, provider: $provider->driver, model: $provider->model, timeout: 60);

                // Restore config
                config(["{$configKey}.key" => $originalKey]);
                config(["{$configKey}.base_url" => $originalBaseUrl]);

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
            }
        }

        throw new Exception('All configured AI providers failed.');
    }
}
