<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppSupportCase extends Model
{
    protected $table = 'whatsapp_support_cases';

    protected $fillable = [
        'ticket_id',
        'contact_id',
        'vendor_id',
        'store_id',
        'category',
        'priority',
        'status',
        'subject',
        'safe_summary',
        'internal_notes',
        'sla_expires_at',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'sla_expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'contact_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
