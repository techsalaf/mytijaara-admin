<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class PendingAction extends Model
{
    protected $table = 'whatsapp_pending_actions';
    protected $guarded = ['id'];
    protected $casts = [
        'payload' => 'array', 'result_metadata' => 'array', 'expires_at' => 'datetime',
        'confirmed_at' => 'datetime', 'executed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];
}
