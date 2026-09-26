<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;

class ProductListingDraft extends Model
{
    protected $table = 'whatsapp_product_drafts';

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'sources' => 'array', 'vision' => 'array', 'errors' => 'array', 'processed_messages' => 'array', 'needs_attention' => 'boolean'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class);
    }
}
