<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppVendorPreference extends Model
{
    protected $table = 'whatsapp_vendor_preferences';

    protected $fillable = [
        'contact_id',
        'vendor_id',
        'opt_in_order_alerts',
        'opt_in_status_alerts',
        'opt_in_stock_alerts',
        'is_paused',
        'quiet_hours_start',
        'quiet_hours_end',
        'locale',
        'consent_audit_log',
    ];

    protected $casts = [
        'opt_in_order_alerts' => 'boolean',
        'opt_in_status_alerts' => 'boolean',
        'opt_in_stock_alerts' => 'boolean',
        'is_paused' => 'boolean',
        'consent_audit_log' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsAppContact::class, 'contact_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
