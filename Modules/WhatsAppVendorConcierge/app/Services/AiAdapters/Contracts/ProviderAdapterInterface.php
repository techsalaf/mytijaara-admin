<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\Contracts;

use Laravel\Ai\Contracts\Agent;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;

interface ProviderAdapterInterface
{
    /**
     * Test connection credentials and return result.
     * @return array{success: bool, message: string, latency_ms: int}
     */
    public function testConnection(AiProviderConnection $connection): array;

    /**
     * Discover available models from the provider.
     * @return array<array{id: string, name: string, supports_tool_calling: bool, supports_vision: bool, is_free_tier: bool, context_window: ?int, cost_per_million_input: float, cost_per_million_output: float}>
     */
    public function discoverModels(AiProviderConnection $connection): array;

    /**
     * Check if adapter supports a given capability ('tools', 'vision', 'streaming', 'discovery').
     */
    public function supportsCapability(string $capability): bool;

    /**
     * Invoke the agent using request-scoped credentials.
     * @return array{response: \Laravel\Ai\Responses\AgentResponse, latency_ms: int, prompt_tokens: int, completion_tokens: int, cost_usd: float}
     */
    public function invokeAgent(
        AiProviderConnection $connection,
        AiProviderModel $model,
        Agent $agent,
        string $prompt,
        array $options = []
    ): array;

    /**
     * Normalise an exception into structured error details.
     * @return array{type: string, message: string, retry_after_seconds: ?int}
     */
    public function normaliseError(\Throwable $e): array;

    /**
     * Extract token usage from a response.
     * @return array{prompt_tokens: int, completion_tokens: int}
     */
    public function extractUsage(mixed $response): array;

    /**
     * Extract rate-limit headers or information.
     */
    public function extractRateLimitState(mixed $responseHeaders): ?array;
}
