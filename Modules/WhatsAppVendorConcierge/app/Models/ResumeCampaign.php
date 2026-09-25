<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class ResumeCampaign extends Model
{
    protected $table = 'whatsapp_resume_campaigns';

    protected $fillable = [
        'name',
        'audience_criteria',
        'meta_template_name',
        'status',
        'sent_count',
        'resumed_count',
        'scheduled_at',
    ];

    protected $casts = [
        'audience_criteria' => 'array',
        'scheduled_at' => 'datetime',
    ];
}
