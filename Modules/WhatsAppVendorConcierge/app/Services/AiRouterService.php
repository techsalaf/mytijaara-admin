<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Modules\WhatsAppVendorConcierge\app\Exceptions\AllAiProvidersFailedException;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingPolicy;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

class AiRouterService
{
    public function __construct(
        protected AiCircuitBreakerService $circuitBreaker
    ) {}

    /**
     * Route an agent prompt to the best available provider/model with automatic failover.
     *
     * @return array{
     *     response: \Laravel\Ai\Responses\AgentResponse,
     *     model: AiProviderModel,
     *     connection: AiProviderConnection,
     *     latency_ms: int,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     cost_usd: float
     * }
     *
     * @throws AllAiProvidersFailedException
     */
    public function routeAndPrompt(Agent $agent, string $prompt, array $options = []): array
    {
        $policySlug = $options['policy'] ?? 'default_concierge';
        $policy = AiRoutingPolicy::where('slug', $policySlug)->where('is_active', true)->first();
        $strategy = $policy?->strategy ?? 'free_first';

        // 1. Classify prompt requirements
        $requiresTools = ($policy?->requires_tool_calling ?? false)
            || ($agent instanceof HasTools && count($agent->tools()) > 0);
        $requiresVision = ($policy?->requires_vision ?? false)
            || !empty($options['attachments'])
            || !empty($options['has_images']);

        // 2. Select eligible candidates
        $candidates = $this->resolveEligibleModels($requiresTools, $requiresVision);

        if ($candidates->isEmpty()) {
            Log::warning('AiRouter: No available models meeting requirements', [
                'requires_tools' => $requiresTools,
                'requires_vision' => $requiresVision,
            ]);
            throw new AllAiProvidersFailedException('No healthy AI providers or models are currently available to handle this request.');
        }

        // 3. Order candidates according to policy strategy
        $ordered = $this->orderCandidatesByStrategy($candidates, $strategy);

        $conversationId = $options['conversation_id'] ?? null;
        $attemptNumber = 1;
        $lastException = null;

        // 4. Execute with instant failover
        foreach ($ordered as $model) {
            $connection = $model->connection;
            if (!$connection) continue;

            $adapter = AdapterFactory::forConnection($connection);

            try {
                $result = $adapter->invokeAgent($connection, $model, $agent, $prompt, $options);

                // Record success
                $this->circuitBreaker->recordSuccess(
                    connection: $connection,
                    model: $model,
                    latencyMs: $result['latency_ms'],
                    promptTokens: $result['prompt_tokens'],
                    completionTokens: $result['completion_tokens'],
                    costUsd: $result['cost_usd'],
                    conversationId: $conversationId,
                    policyId: $policy?->id
                );

                Log::info('AiRouter: Model prompt succeeded', [
                    'model' => $model->model_id,
                    'connection' => $connection->name,
                    'is_free' => $model->is_free_tier,
                    'latency_ms' => $result['latency_ms'],
                    'attempt' => $attemptNumber,
                ]);

                return [
                    'response' => $result['response'],
                    'model' => $model,
                    'connection' => $connection,
                    'latency_ms' => $result['latency_ms'],
                    'prompt_tokens' => $result['prompt_tokens'],
                    'completion_tokens' => $result['completion_tokens'],
                    'cost_usd' => $result['cost_usd'],
                ];

            } catch (\Throwable $e) {
                $lastException = $e;

                $this->circuitBreaker->recordFailure(
                    connection: $connection,
                    model: $model,
                    e: $e,
                    conversationId: $conversationId,
                    policyId: $policy?->id
                );

                Log::warning('AiRouter: Model prompt failed, falling back to next candidate', [
                    'failed_model' => $model->model_id,
                    'failed_connection' => $connection->name,
                    'attempt' => $attemptNumber,
                    'error' => $e->getMessage(),
                ]);

                $attemptNumber++;
            }
        }

        throw new AllAiProvidersFailedException(
            'All eligible AI providers failed: ' . ($lastException?->getMessage() ?? 'unknown error'),
            previous: $lastException
        );
    }

    /**
     * Resolve all models that are enabled, available, and meet capability requirements.
     *
     * @return Collection<int, AiProviderModel>
     */
    public function resolveEligibleModels(bool $requiresTools = false, bool $requiresVision = false): Collection
    {
        $query = AiProviderModel::with(['connection.definition'])
            ->where('is_enabled', true)
            ->whereHas('connection', function ($q) {
                $q->where('is_active', true)
                  ->whereNotIn('status', ['disabled', 'auth_failed', 'budget_exhausted']);
            });

        if ($requiresTools) {
            $query->where('supports_tool_calling', true);
        }

        if ($requiresVision) {
            $query->where('supports_vision', true);
        }

        $models = $query->get();

        // Further filter by dynamic circuit breaker availability (cooldowns, rate limits, budgets)
        return $models->filter(function (AiProviderModel $model) {
            return $model->connection && $this->circuitBreaker->isConnectionAvailable($model->connection);
        })->values();
    }

    /**
     * Order eligible candidate models by strategy.
     *
     * @param Collection<int, AiProviderModel> $candidates
     * @return Collection<int, AiProviderModel>
     */
    public function orderCandidatesByStrategy(Collection $candidates, string $strategy): Collection
    {
        return match ($strategy) {
            'strict_fallback' => $candidates->sortBy([
                ['priority', 'asc'],
                ['weight', 'desc'],
            ])->values(),

            'lowest_cost' => $candidates->sortBy(function (AiProviderModel $model) {
                return (float) $model->cost_per_million_input + (float) $model->cost_per_million_output;
            })->values(),

            'quality_first' => $candidates->sortBy([
                ['is_free_tier', 'asc'], // Paid first (0 then 1)
                ['priority', 'asc'],
                ['weight', 'desc'],
            ])->values(),

            'round_robin' => $this->applyRoundRobin($candidates),

            // Default 'free_first'
            default => $candidates->sortBy([
                ['is_free_tier', 'desc'], // Free first (1 then 0)
                ['priority', 'asc'],
                ['weight', 'desc'],
            ])->values(),
        };
    }

    /**
     * Apply round-robin cycling across top priority models.
     */
    protected function applyRoundRobin(Collection $candidates): Collection
    {
        if ($candidates->count() <= 1) {
            return $candidates;
        }

        $cacheKey = 'ai_router_round_robin_idx';
        $idx = (int) Cache::get($cacheKey, 0);

        $count = $candidates->count();
        $offset = $idx % $count;
        Cache::put($cacheKey, ($idx + 1) % $count, 3600);

        return $candidates->slice($offset)->merge($candidates->slice(0, $offset))->values();
    }
}
