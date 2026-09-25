<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConciergeRecoveryAudit extends Model
{
    protected $table = 'concierge_recovery_audits';

    protected $fillable = [
        'conversation_id',
        'contact_id',
        'action',
        'initiated_by',
        'admin_id',
        'previous_state',
        'proposed_state',
        'previous_step',
        'is_dry_run',
        'status',
        'reason',
        'details',
        'correlation_id',
    ];

    protected $casts = [
        'is_dry_run' => 'boolean',
        'details' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'contact_id');
    }
}
