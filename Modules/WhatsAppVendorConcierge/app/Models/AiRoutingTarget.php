<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRoutingTarget extends Model
{
    protected $table = 'ai_routing_targets';

    protected $fillable = [
        'policy_id',
        'model_id',
        'priority',
        'weight',
        'is_fallback',
    ];

    protected $casts = [
        'priority' => 'integer',
        'weight' => 'integer',
        'is_fallback' => 'boolean',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'policy_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiProviderModel::class, 'model_id');
    }
}
