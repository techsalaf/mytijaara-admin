<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RuntimeSettings
{
    public const KEYS = ['enabled', 'rollout_percent', 'test_phones', 'fallback_to_chat', 'flow_id', 'mode', 'session_minutes', 'definition_version', 'current_terms_version', 'current_privacy_version', 'max_image_bytes', 'max_document_bytes', 'max_dimension', 'max_pixels', 'cleanup_schedule', 'abandonment_hours', 'analytics_retention_days'];

    public function apply(): void
    {
        if (! Schema::hasTable('wa_flow_control_settings')) {
            return;
        }
        $values = [];
        try {
            foreach (DB::table('wa_flow_control_settings')->whereIn('key', self::KEYS)->get() as $row) {
                $value = json_decode($row->value, true, 8, JSON_THROW_ON_ERROR);
                $this->validate($row->key, $value);
                $values[$row->key] = $value;
            }
        } catch (\Throwable) {
            config(['whatsapp-vendor-flow.enabled' => false, 'whatsapp-vendor-flow.fallback_to_chat' => true, 'whatsapp-vendor-flow.runtime_error' => 'invalid_persisted_settings']);

            return;
        }
        foreach ($values as $key => $value) {
            $this->configure($key, $value);
        }
    }

    public function put(array $values): void
    {
        $this->lock();
        foreach ($values as $key => $value) {
            $this->validate($key, $value);
        }
        foreach ($values as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                throw new Failure('Unsupported runtime setting.');
            }
            DB::table('wa_flow_control_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]);
            $this->configure($key, $value);
        }
    }

    public function lock(): void
    {
        if (Schema::hasTable('wa_flow_control_settings')) {
            DB::table('wa_flow_control_settings')->where('key', 'control_gate')->lockForUpdate()->first();
        }
    }

    private function validate(string $key, mixed $value): void
    {
        $valid = match ($key) {
            'enabled', 'fallback_to_chat' => is_bool($value),
            'rollout_percent' => is_int($value) && $value >= 0 && $value <= 100,
            'session_minutes' => is_int($value) && $value >= 10 && $value <= 1440,
            'max_image_bytes', 'max_document_bytes' => is_int($value) && $value >= 65536 && $value <= 2097152,
            'max_dimension' => is_int($value) && $value >= 256 && $value <= 6000,
            'max_pixels' => is_int($value) && $value >= 65536 && $value <= 16000000,
            'cleanup_schedule' => in_array($value, ['disabled', 'daily', 'hourly'], true),
            'abandonment_hours' => is_int($value) && $value >= 1 && $value <= 720,
            'analytics_retention_days' => $value === null || (is_int($value) && $value >= 1 && $value <= 3650),
            'flow_id' => is_string($value) && ($value === '' || preg_match('/^[0-9]{1,30}$/D', $value)),
            'mode' => in_array($value, ['draft', 'published'], true),
            'definition_version' => is_string($value) && preg_match('/^vendor-onboarding-[a-zA-Z0-9._-]{1,60}$/D', $value),
            'current_terms_version', 'current_privacy_version' => is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $value),
            'test_phones' => is_array($value) && array_is_list($value) && count($value) <= 20 && ! array_filter($value, fn ($v) => ! is_string($v) || ! preg_match('/^[1-9][0-9]{7,14}$/D', $v)),
            default => false,
        };
        if (! $valid) {
            throw new Failure('Unsupported or invalid runtime setting.');
        }
    }

    private function configure(string $key, mixed $value): void
    {
        if ($key === 'current_terms_version' || $key === 'current_privacy_version') {
            config(['registration-policies.current.en.'.($key === 'current_terms_version' ? 'terms' : 'privacy') => $value]);
        } else {
            config(['whatsapp-vendor-flow.'.$key => $value]);
        }
    }
}
