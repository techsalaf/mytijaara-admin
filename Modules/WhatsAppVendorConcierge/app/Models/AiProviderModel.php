<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class AiProviderModel extends Model
{
    protected $table = 'ai_provider_models';

    protected $fillable = [
        'connection_id',
        'model_id',
        'name',
        'is_enabled',
        'supports_tool_calling',
        'supports_vision',
        'is_free_tier',
        'context_window',
        'max_output_tokens',
        'cost_per_million_input',
        'cost_per_million_output',
        'priority',
        'weight',
        'capabilities_override',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'supports_tool_calling' => 'boolean',
        'supports_vision' => 'boolean',
        'is_free_tier' => 'boolean',
        'context_window' => 'integer',
        'max_output_tokens' => 'integer',
        'cost_per_million_input' => 'decimal:4',
        'cost_per_million_output' => 'decimal:4',
        'priority' => 'integer',
        'weight' => 'integer',
        'capabilities_override' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiProviderConnection::class, 'connection_id');
    }

    public function definition(): HasOneThrough
    {
        return $this->hasOneThrough(
            AiProviderDefinition::class,
            AiProviderConnection::class,
            'id', // Foreign key on ai_provider_connections table...
            'id', // Foreign key on ai_provider_definitions table...
            'connection_id', // Local key on ai_provider_models table...
            'definition_id'  // Local key on ai_provider_connections table...
        );
    }

    public function routingTargets(): HasMany
    {
        return $this->hasMany(AiRoutingTarget::class, 'model_id');
    }

    public function isAvailable(): bool
    {
        return $this->is_enabled && $this->connection && $this->connection->isAvailable();
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeFreeTier($query)
    {
        return $query->where('is_free_tier', true);
    }

    public function scopeToolCapable($query)
    {
        return $query->where('supports_tool_calling', true);
    }
}
