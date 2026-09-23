<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRoutingPolicy extends Model
{
    protected $table = 'ai_routing_policies';

    protected $fillable = [
        'name',
        'slug',
        'strategy',
        'requires_tool_calling',
        'requires_vision',
        'max_latency_ms',
        'max_cost_per_turn_usd',
        'is_active',
    ];

    protected $casts = [
        'requires_tool_calling' => 'boolean',
        'requires_vision' => 'boolean',
        'max_latency_ms' => 'integer',
        'max_cost_per_turn_usd' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(AiRoutingTarget::class, 'policy_id')->orderBy('priority', 'asc');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
