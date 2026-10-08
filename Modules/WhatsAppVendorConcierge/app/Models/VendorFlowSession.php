<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class VendorFlowSession extends Model
{
    protected $table = 'wa_vendor_flow_sessions';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'sender', 'draft', 'policy_manifest'];

    protected $casts = ['draft' => 'encrypted:array', 'policy_manifest' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'credential_setup_completed_at' => 'immutable_datetime'];
}
