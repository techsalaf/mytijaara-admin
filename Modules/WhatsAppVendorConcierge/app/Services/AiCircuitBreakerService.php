<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Models\AiRoutingAttempt;
use Modules\WhatsAppVendorConcierge\app\Models\AiUsageRecord;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\AdapterFactory;

class AiCircuitBreakerService
{
    const MAX_CONSECUTIVE_FAILURES_BEFORE_COOLDOWN = 3;
    const MAX_COOLDOWN_SECONDS = 300;

    /**
     * Check if a connection is currently available and handle automatic status recovery.
     */
    public function isConnectionAvailable(AiProviderConnection $connection): bool
    {
        $now = now();

        // 1. Budget period roll-over checks
        $this->ensureBudgetWindowCurrent($connection);

        // 2. Cooldown auto-recovery
        if ($connection->status === 'cooldown' && $connection->cooldown_until && $connection->cooldown_until->isPast()) {
            $connection->update([
                'status' => 'healthy',
                'cooldown_until' => null,
                'consecutive_failures' => 0,
            ]);
            $connection->refresh();
        }

        // 3. Rate limit reset auto-recovery
        if ($connection->status === 'rate_limited' && $connection->rate_limit_reset_at && $connection->rate_limit_reset_at->isPast()) {
            $connection->update([
                'status' => 'healthy',
                'rate_limit_reset_at' => null,
            ]);
            $connection->refresh();
        }

        return $connection->isAvailable();
    }

    /**
     * Record a successful model invocation.
     */
    public function recordSuccess(
        AiProviderConnection $connection,
        ?AiProviderModel $model,
        int $latencyMs,
        int $promptTokens,
        int $completionTokens,
        float $costUsd,
        ?int $conversationId = null,
        ?int $policyId = null
    ): void {
        // Reset failures & recover status
        $newStatus = in_array($connection->status, ['degraded', 'cooldown'], true) ? 'healthy' : $connection->status;

        $this->updateBudgetUsage($connection, $costUsd, $newStatus);

        // Bounded attempt recording
        AiRoutingAttempt::create([
            'conversation_id' => $conversationId,
            'policy_id' => $policyId,
            'connection_id' => $connection->id,
            'model_id' => $model?->id,
            'status' => 'success',
            'error_message' => null,
            'latency_ms' => $latencyMs,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => $costUsd,
        ]);

        // Aggregate usage record for today
        $this->incrementDailyUsage(
            $connection->id,
            $model?->id,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            costUsd: $costUsd,
            successful: true
        );
    }

    /**
     * Record a failed invocation and adjust circuit breaker states.
     */
    public function recordFailure(
        AiProviderConnection $connection,
        ?AiProviderModel $model,
        \Throwable $e,
        ?int $conversationId = null,
        ?int $policyId = null
    ): array {
        $adapter = AdapterFactory::forConnection($connection);
        $norm = $adapter->normaliseError($e);

        $failures = $connection->consecutive_failures + 1;
        $updates = [
            'consecutive_failures' => $failures,
            'last_error' => substr($e->getMessage(), 0, 1000),
        ];

        switch ($norm['type']) {
            case 'auth_failed':
                $updates['status'] = 'auth_failed';
                break;

            case 'rate_limit':
                $updates['status'] = 'rate_limited';
                $retryAfter = $norm['retry_after_seconds'] ?? 60;
                $updates['rate_limit_reset_at'] = now()->addSeconds($retryAfter);
                break;

            default:
                if ($failures >= self::MAX_CONSECUTIVE_FAILURES_BEFORE_COOLDOWN) {
                    $backoff = min(self::MAX_COOLDOWN_SECONDS, 15 * (2 ** ($failures - self::MAX_CONSECUTIVE_FAILURES_BEFORE_COOLDOWN)));
                    $updates['status'] = 'cooldown';
                    $updates['cooldown_until'] = now()->addSeconds($backoff);
                } else {
                    $updates['status'] = 'degraded';
                }
                break;
        }

        $connection->update($updates);

        // Record attempt
        AiRoutingAttempt::create([
            'conversation_id' => $conversationId,
            'policy_id' => $policyId,
            'connection_id' => $connection->id,
            'model_id' => $model?->id,
            'status' => $norm['type'],
            'error_message' => substr($e->getMessage(), 0, 1000),
            'latency_ms' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_usd' => 0.0,
        ]);

        // Aggregate usage record for today
        $this->incrementDailyUsage(
            $connection->id,
            $model?->id,
            promptTokens: 0,
            completionTokens: 0,
            costUsd: 0.0,
            successful: false
        );

        Log::warning('Circuit breaker recorded failure for AI connection', [
            'connection_id' => $connection->id,
            'status' => $updates['status'],
            'failures' => $failures,
            'error_type' => $norm['type'],
        ]);

        return $norm;
    }

    /**
     * Ensure daily and monthly budget reset windows are up to date.
     */
    protected function ensureBudgetWindowCurrent(AiProviderConnection $connection): void
    {
        $today = now()->toDateString();
        $thisMonth = now()->format('Y-m');

        $updates = [];

        $resetDay = $connection->cost_reset_day instanceof \DateTimeInterface
            ? $connection->cost_reset_day->format('Y-m-d')
            : $connection->cost_reset_day;

        if ($resetDay !== $today) {
            $updates['cost_reset_day'] = $today;
            $updates['current_day_cost_usd'] = 0.0;
        }

        if ($connection->cost_reset_month !== $thisMonth) {
            $updates['cost_reset_month'] = $thisMonth;
            $updates['current_month_cost_usd'] = 0.0;
        }

        if (!empty($updates)) {
            $connection->update($updates);
            $connection->refresh();
        }
    }

    /**
     * Update running daily and monthly costs and check budget ceilings.
     */
    protected function updateBudgetUsage(AiProviderConnection $connection, float $costUsd, string $targetStatus): void
    {
        $this->ensureBudgetWindowCurrent($connection);

        $newDayCost = (float) $connection->current_day_cost_usd + $costUsd;
        $newMonthCost = (float) $connection->current_month_cost_usd + $costUsd;

        $status = $targetStatus;

        if ($connection->daily_budget_usd !== null && $newDayCost >= (float) $connection->daily_budget_usd) {
            $status = 'budget_exhausted';
        } elseif ($connection->monthly_budget_usd !== null && $newMonthCost >= (float) $connection->monthly_budget_usd) {
            $status = 'budget_exhausted';
        }

        $connection->update([
            'status' => $status,
            'consecutive_failures' => 0,
            'current_day_cost_usd' => $newDayCost,
            'current_month_cost_usd' => $newMonthCost,
        ]);
    }

    /**
     * Increment usage stats in the ai_usage_records aggregate table.
     */
    protected function incrementDailyUsage(
        int $connectionId,
        ?int $modelId,
        int $promptTokens,
        int $completionTokens,
        float $costUsd,
        bool $successful
    ): void {
        $today = now()->toDateString();

        $record = AiUsageRecord::firstOrCreate(
            [
                'connection_id' => $connectionId,
                'model_id' => $modelId,
                'usage_date' => $today,
            ],
            [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'total_prompt_tokens' => 0,
                'total_completion_tokens' => 0,
                'total_cost_usd' => 0.0,
            ]
        );

        $record->increment('total_requests');
        if ($successful) {
            $record->increment('successful_requests');
        } else {
            $record->increment('failed_requests');
        }

        if ($promptTokens > 0) {
            $record->increment('total_prompt_tokens', $promptTokens);
        }
        if ($completionTokens > 0) {
            $record->increment('total_completion_tokens', $completionTokens);
        }
        if ($costUsd > 0) {
            $record->update(['total_cost_usd' => (float) $record->total_cost_usd + $costUsd]);
        }
    }
}
