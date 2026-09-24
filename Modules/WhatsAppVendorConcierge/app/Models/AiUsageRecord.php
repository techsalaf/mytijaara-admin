<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageRecord extends Model
{
    protected $table = 'ai_usage_records';

    protected $fillable = [
        'connection_id',
        'model_id',
        'usage_date',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'total_prompt_tokens',
        'total_completion_tokens',
        'total_cost_usd',
    ];

    protected $casts = [
        'usage_date' => 'string',
        'total_requests' => 'integer',
        'successful_requests' => 'integer',
        'failed_requests' => 'integer',
        'total_prompt_tokens' => 'integer',
        'total_completion_tokens' => 'integer',
        'total_cost_usd' => 'decimal:6',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AiProviderConnection::class, 'connection_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiProviderModel::class, 'model_id');
    }
}
