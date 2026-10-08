<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use App\Services\VendorAuthenticationEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Diagnostics
{
    public function summary(): array
    {
        $out = ['worker_health' => 'unknown: queue counts do not prove worker availability', 'cleanup_schedule' => config('whatsapp-vendor-flow.cleanup_schedule'), 'analytics_retention_days' => config('whatsapp-vendor-flow.analytics_retention_days')];
        foreach (['jobs', 'failed_jobs'] as $table) {
            $out[$table.'_count'] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }
        $out['last_completed_operation_at'] = DB::table('wa_flow_control_operations')->whereIn('state', ['succeeded', 'failed'])->max('updated_at');
        $out['stale_operations'] = DB::table('wa_flow_control_operations')->whereIn('state', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(10))->count();
        $out['last_policy_integrity'] = DB::table('wa_flow_control_audits')->where('action', 'policy_integrity')->latest('id')->first(['outcome', 'created_at', 'safe_values']);
        $out['last_cleanup'] = DB::table('wa_flow_control_audits')->where('action', 'scheduled_cleanup')->latest('id')->first(['outcome', 'created_at']);
        $out['token_audit'] = DB::table('wa_flow_control_operations')->where('action', 'token_audit')->latest('created_at')->first(['state', 'result', 'updated_at']);

        return $out;
    }

    public function tokenAudit(): array
    {
        $counts = ['examined' => 0, 'ineligible' => 0, 'revoked' => 0, 'truncated' => false];
        $policy = app(VendorAuthenticationEligibility::class);
        foreach ([Vendor::class, VendorEmployee::class] as $class) {
            $rows = $class::withoutGlobalScopes()->whereNotNull('auth_token')->where('auth_token', '!=', '')->limit(501)->get();
            $counts['truncated'] = $counts['truncated'] || $rows->count() > 500;
            foreach ($rows->take(500) as $principal) {
                $employee = $class === VendorEmployee::class;
                $vendor = $employee ? Vendor::withoutGlobalScopes()->find($principal->vendor_id) : $principal;
                $store = Store::withoutGlobalScopes()->when($employee, fn ($q) => $q->whereKey($principal->store_id), fn ($q) => $q->where('vendor_id', $principal->id))->orderBy('id')->first();
                $counts['examined']++;
                if (! $policy->evaluate($vendor, $store, 'api', $employee ? $principal : null)->eligible) {
                    $counts['ineligible']++;
                }
            }
        }

        return $counts + ['mode' => 'Read-only eligibility audit; no token values, revocation or issuance-age inference.'];
    }
}
