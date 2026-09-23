<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\AiAdapters;

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderConnection;
use Modules\WhatsAppVendorConcierge\app\Models\AiProviderModel;
use Modules\WhatsAppVendorConcierge\app\Services\AiAdapters\Contracts\ProviderAdapterInterface;

abstract class BaseProviderAdapter implements ProviderAdapterInterface
{
    public function normaliseError(\Throwable $e): array
    {
        $message = $e->getMessage();
        $code = (int) $e->getCode();
        $retryAfter = null;

        // Try extracting retry-after from message or headers
        if (preg_match('/(?:retry[- ]after|try again in) (\d+)/i', $message, $matches)) {
            $retryAfter = (int) $matches[1];
        }

        $lowerMsg = strtolower($message);

        if ($code === 429 || str_contains($lowerMsg, 'rate limit') || str_contains($lowerMsg, 'quota exceeded') || str_contains($lowerMsg, 'too many requests')) {
            return [
                'type' => 'rate_limit',
                'message' => $message,
                'retry_after_seconds' => $retryAfter ?: 60,
            ];
        }

        if ($code === 401 || $code === 403 || str_contains($lowerMsg, 'unauthorized') || str_contains($lowerMsg, 'authentication') || str_contains($lowerMsg, 'invalid api key')) {
            return [
                'type' => 'auth_failed',
                'message' => $message,
                'retry_after_seconds' => null,
            ];
        }

        if ($code === 408 || $code === 504 || str_contains($lowerMsg, 'timeout') || str_contains($lowerMsg, 'timed out')) {
            return [
                'type' => 'timeout',
                'message' => $message,
                'retry_after_seconds' => 15,
            ];
        }

        if (in_array($code, [500, 502, 503], true) || str_contains($lowerMsg, 'overloaded') || str_contains($lowerMsg, 'service unavailable')) {
            return [
                'type' => 'overloaded',
                'message' => $message,
                'retry_after_seconds' => 30,
            ];
        }

        return [
            'type' => 'unknown',
            'message' => $message,
            'retry_after_seconds' => null,
        ];
    }

    public function extractUsage(mixed $response): array
    {
        if ($response instanceof AgentResponse) {
            $usage = $response->usage;
            return [
                'prompt_tokens' => (int) ($usage->promptTokens ?? 0),
                'completion_tokens' => (int) ($usage->completionTokens ?? 0),
            ];
        }

        if (is_array($response) && isset($response['usage'])) {
            return [
                'prompt_tokens' => (int) ($response['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($response['usage']['completion_tokens'] ?? 0),
            ];
        }

        return [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ];
    }

    public function calculateCost(AiProviderModel $model, int $promptTokens, int $completionTokens): float
    {
        if ($model->is_free_tier) {
            return 0.0;
        }

        $inputRate = (float) $model->cost_per_million_input;
        $outputRate = (float) $model->cost_per_million_output;

        $inputCost = ($promptTokens / 1_000_000) * $inputRate;
        $outputCost = ($completionTokens / 1_000_000) * $outputRate;

        return round($inputCost + $outputCost, 6);
    }

    public function extractRateLimitState(mixed $responseHeaders): ?array
    {
        if (!is_array($responseHeaders)) {
            return null;
        }

        $state = [];
        if (isset($responseHeaders['x-ratelimit-remaining-requests'])) {
            $state['remaining_requests'] = (int) ($responseHeaders['x-ratelimit-remaining-requests'][0] ?? $responseHeaders['x-ratelimit-remaining-requests']);
        }
        if (isset($responseHeaders['x-ratelimit-reset-requests'])) {
            $state['reset_requests'] = $responseHeaders['x-ratelimit-reset-requests'][0] ?? $responseHeaders['x-ratelimit-reset-requests'];
        }
        if (isset($responseHeaders['retry-after'])) {
            $state['retry_after'] = (int) ($responseHeaders['retry-after'][0] ?? $responseHeaders['retry-after']);
        }

        return !empty($state) ? $state : null;
    }
}
