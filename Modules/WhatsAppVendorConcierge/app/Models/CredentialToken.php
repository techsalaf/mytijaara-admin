<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class CredentialToken extends Model
{
    protected $table = 'whatsapp_credential_tokens';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash', 'creation_metadata'];
    protected $casts = [
        'expires_at' => 'datetime', 'consumed_at' => 'datetime',
        'revoked_at' => 'datetime', 'creation_metadata' => 'array',
    ];
}
