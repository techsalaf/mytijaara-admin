<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class AiProviderDefinition extends Model
{
    protected $table = 'ai_provider_definitions';

    protected $fillable = [
        'slug',
        'name',
        'adapter_class',
        'default_base_url',
        'auth_type',
        'supports_model_discovery',
        'supports_tool_calling',
        'supports_vision',
        'supports_streaming',
        'catalogue_models',
        'documentation_url',
        'is_active',
    ];

    protected $casts = [
        'supports_model_discovery' => 'boolean',
        'supports_tool_calling' => 'boolean',
        'supports_vision' => 'boolean',
        'supports_streaming' => 'boolean',
        'catalogue_models' => 'array',
        'is_active' => 'boolean',
    ];

    public function connections(): HasMany
    {
        return $this->hasMany(AiProviderConnection::class, 'definition_id');
    }

    public function models(): HasManyThrough
    {
        return $this->hasManyThrough(AiProviderModel::class, AiProviderConnection::class, 'definition_id', 'connection_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
