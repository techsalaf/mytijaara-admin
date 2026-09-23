<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRoutingAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'ai_routing_attempts';

    protected $fillable = [
        'conversation_id',
        'policy_id',
        'connection_id',
        'model_id',
        'attempt_number',
        'status',
        'error_message',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
        'created_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'latency_ms' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiProviderConnection::class, 'connection_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiProviderModel::class, 'model_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AiRoutingPolicy::class, 'policy_id');
    }
}
