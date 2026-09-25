<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class ConciergeHealthCheck extends Model
{
    protected $table = 'concierge_health_checks';

    protected $fillable = [
        'check_type',
        'total_active_conversations',
        'waiting_for_concierge',
        'waiting_for_user',
        'in_human_handoff',
        'stale_human_handoff',
        'silenced_count',
        'stuck_count',
        'failed_sends_count',
        'auto_recovered_count',
        'issues_requiring_human',
        'issues_requiring_code',
        'summary',
    ];

    protected $casts = [
        'summary' => 'array',
    ];
}
