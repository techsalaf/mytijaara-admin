<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppAiUsageLog;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;

class AiBudgetService
{
    public const MAX_HOURLY_TURNS_PER_CONTACT = 50;
    public const MAX_DAILY_TURNS_PER_VENDOR = 200;
    public const MAX_CONSECUTIVE_FAILURES = 5;
    public const CIRCUIT_BREAKER_TTL_SECONDS = 600; // 10 mins
    public const MAX_HISTORY_TURNS = 10;

    /**
     * Check if an AI request is permitted within rate limits and budget.
     */
    public function canInvokeAi(WhatsAppContact $contact, ?Vendor $vendor = null): array
    {
        // 1. Check global circuit breaker
        if (Cache::get('whatsapp_ai_circuit_broken', false)) {
            return [
                'allowed' => false,
                'reason' => 'circuit_breaker_active',
                'fallback_message' => "Our AI assistant is temporarily resting. 🛠️\n\nReply with MENU to see options or SUPPORT to talk to our team.",
            ];
        }

        // 2. Check per-contact hourly turns
        $contactHourlyKey = "whatsapp_ai_turns_contact_{$contact->id}_" . Carbon::now()->format('YmdH');
        $contactTurns = (int) Cache::get($contactHourlyKey, 0);
        if ($contactTurns >= self::MAX_HOURLY_TURNS_PER_CONTACT) {
            return [
                'allowed' => false,
                'reason' => 'contact_hourly_rate_limit',
                'fallback_message' => "You have reached the hourly message limit for AI assistance.\n\nPlease reply MENU for self-service options or SUPPORT to reach our team.",
            ];
        }

        // 3. Check per-vendor daily turns
        if ($vendor) {
            $vendorDailyKey = "whatsapp_ai_turns_vendor_{$vendor->id}_" . Carbon::now()->format('Ymd');
            $vendorTurns = (int) Cache::get($vendorDailyKey, 0);
            if ($vendorTurns >= self::MAX_DAILY_TURNS_PER_VENDOR) {
                return [
                    'allowed' => false,
                    'reason' => 'vendor_daily_rate_limit',
                    'fallback_message' => "Your store has reached its daily AI budget. Deterministic commands and dashboard access remain active.\nReply MENU for commands.",
                ];
            }
        }

        return ['allowed' => true, 'reason' => 'within_budget'];
    }

    /**
     * Increment usage turn counters.
     */
    public function recordTurn(WhatsAppContact $contact, ?Vendor $vendor = null): void
    {
        $contactHourlyKey = "whatsapp_ai_turns_contact_{$contact->id}_" . Carbon::now()->format('YmdH');
        $turns = (int) Cache::get($contactHourlyKey, 0);
        Cache::put($contactHourlyKey, $turns + 1, 3600);

        if ($vendor) {
            $vendorDailyKey = "whatsapp_ai_turns_vendor_{$vendor->id}_" . Carbon::now()->format('Ymd');
            $vTurns = (int) Cache::get($vendorDailyKey, 0);
            Cache::put($vendorDailyKey, $vTurns + 1, 86400);
        }
    }

    /**
     * Record completed usage and estimated cost into database log.
     */
    public function logAiUsage(
        WhatsAppContact $contact,
        ?Vendor $vendor,
        string $model,
        int $promptTokens,
        int $completionTokens,
        float $cost = 0.0,
        bool $isFallback = false
    ): WhatsAppAiUsageLog {
        // Reset consecutive failure count on success
        Cache::forget('whatsapp_ai_consecutive_failures');

        return WhatsAppAiUsageLog::create([
            'contact_id' => $contact->id,
            'vendor_id' => $vendor?->id,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'estimated_cost' => $cost,
            'is_fallback' => $isFallback,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Record failure and potentially trip the circuit breaker.
     */
    public function recordFailure(\Throwable $e): void
    {
        $failures = (int) Cache::get('whatsapp_ai_consecutive_failures', 0) + 1;
        Cache::put('whatsapp_ai_consecutive_failures', $failures, 3600);

        Log::warning('WhatsApp AI failure recorded', [
            'consecutive_failures' => $failures,
            'error' => $e->getMessage(),
        ]);

        if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
            Cache::put('whatsapp_ai_circuit_broken', true, self::CIRCUIT_BREAKER_TTL_SECONDS);
            Log::emergency('WhatsApp AI circuit breaker tripped due to consecutive failures', [
                'failures' => $failures,
                'ttl_seconds' => self::CIRCUIT_BREAKER_TTL_SECONDS,
            ]);
        }
    }

    /**
     * Sanitize user text before sending to AI: strips bank account numbers, BVN/NIN, passwords.
     */
    public function sanitizePromptInput(string $text): string
    {
        // 1. Redact 10-11 digit numbers (Bank account, BVN, NIN)
        $redacted = preg_replace('/\b\d{10,11}\b/', '[REDACTED_NUMBER]', $text);

        // 2. Redact passwords or pin phrases
        $redacted = preg_replace('/(?i)(password|pin|passcode|token)\s*[:=]\s*\S+/', '$1: [REDACTED]', $redacted);

        // 3. Redact email addresses if present
        $redacted = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $redacted);

        return $redacted;
    }

    /**
     * Truncate conversation history to avoid context-window bloat and excessive token costs.
     */
    public function limitHistory(array $history, int $limit = self::MAX_HISTORY_TURNS): array
    {
        if (count($history) <= $limit) {
            return $history;
        }

        return array_slice($history, -$limit);
    }
}
