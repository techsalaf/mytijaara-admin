<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $table = 'whatsapp_notification_deliveries';
    protected $guarded = ['id'];
    protected $casts = ['sent_at' => 'datetime', 'metadata' => 'array'];
}
