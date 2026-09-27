<?php
namespace Modules\WhatsAppVendorConcierge\app\Models;
use Illuminate\Database\Eloquent\Model;
class InboundReceipt extends Model {
    protected $table = 'whatsapp_inbound_receipts';
    protected $guarded = [];
    protected $casts = ['completed_at' => 'datetime'];
}
