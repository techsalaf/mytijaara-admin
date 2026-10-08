<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use Illuminate\Support\Facades\DB;

class Audit
{
    public function record(int $admin, string $action, ?string $target, string $outcome, array $safe = [], ?string $operation = null): void
    {
        // Callers supply only allowlisted operational values, never request bodies or exceptions.
        DB::table('wa_flow_control_audits')->insert(['admin_id' => $admin, 'operation_id' => $operation, 'action' => $action, 'target' => $target, 'outcome' => $outcome, 'safe_values' => json_encode($safe, JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
    }
}
