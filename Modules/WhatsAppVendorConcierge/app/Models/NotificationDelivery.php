<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $table = 'whatsapp_notification_deliveries';
    protected $guarded = ['id'];

    protected $casts = [
        'sent_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'attempt_count' => 'integer',
        'metadata' => 'array',
    ];

    public function isClaimActive(): bool
    {
        return $this->status === 'processing'
            && $this->claim_expires_at !== null
            && $this->claim_expires_at->isFuture();
    }

    public function hasExceededAttempts(int $max = 5): bool
    {
        return $this->attempt_count >= $max;
    }
}
