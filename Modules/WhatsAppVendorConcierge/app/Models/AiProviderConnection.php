<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProviderConnection extends Model
{
    protected $table = 'ai_provider_connections';

    protected $fillable = [
        'definition_id',
        'name',
        'credentials',
        'base_url_override',
        'auth_state',
        'is_active',
        'status',
        'consecutive_failures',
        'cooldown_until',
        'rate_limit_reset_at',
        'daily_budget_usd',
        'monthly_budget_usd',
        'current_day_cost_usd',
        'current_month_cost_usd',
        'cost_reset_day',
        'cost_reset_month',
        'selection_mode',
        'last_tested_at',
        'last_error',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'auth_state' => 'array',
        'is_active' => 'boolean',
        'consecutive_failures' => 'integer',
        'cooldown_until' => 'datetime',
        'rate_limit_reset_at' => 'datetime',
        'last_tested_at' => 'datetime',
        'cost_reset_day' => 'date',
        'daily_budget_usd' => 'decimal:4',
        'monthly_budget_usd' => 'decimal:4',
        'current_day_cost_usd' => 'decimal:4',
        'current_month_cost_usd' => 'decimal:4',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AiProviderDefinition::class, 'definition_id');
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiProviderModel::class, 'connection_id');
    }

    public function enabledModels(): HasMany
    {
        return $this->hasMany(AiProviderModel::class, 'connection_id')
            ->where('is_enabled', true)
            ->orderBy('priority', 'asc');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(AiUsageRecord::class, 'connection_id');
    }

    public function routingAttempts(): HasMany
    {
        return $this->hasMany(AiRoutingAttempt::class, 'connection_id');
    }

    public function getApiKey(): ?string
    {
        $creds = $this->credentials;
        if (is_array($creds)) {
            return $creds['api_key'] ?? $creds['key'] ?? null;
        }
        return null;
    }

    public function getBaseUrl(): ?string
    {
        return $this->base_url_override ?: $this->definition?->default_base_url;
    }

    public function isAvailable(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (in_array($this->status, ['disabled', 'auth_failed', 'budget_exhausted'], true)) {
            return false;
        }

        if ($this->cooldown_until && $this->cooldown_until->isFuture()) {
            return false;
        }

        if ($this->rate_limit_reset_at && $this->rate_limit_reset_at->isFuture()) {
            return false;
        }

        if ($this->daily_budget_usd !== null && (float) $this->current_day_cost_usd >= (float) $this->daily_budget_usd) {
            return false;
        }

        if ($this->monthly_budget_usd !== null && (float) $this->current_month_cost_usd >= (float) $this->monthly_budget_usd) {
            return false;
        }

        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->whereNotIn('status', ['disabled', 'auth_failed', 'budget_exhausted'])
            ->where(function ($q) {
                $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('rate_limit_reset_at')->orWhere('rate_limit_reset_at', '<=', now());
            });
    }
}
