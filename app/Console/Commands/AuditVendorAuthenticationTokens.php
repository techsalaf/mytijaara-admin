<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use App\Services\VendorAuthenticationEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditVendorAuthenticationTokens extends Command
{
    protected $signature = 'vendor:tokens-audit {--revoke : Enable revocation mode} {--execute : Apply revocation; otherwise dry-run} {--chunk=200}';

    protected $description = 'Redacted audit of current vendor/employee bearer eligibility; dry-run by default';

    public function handle(VendorAuthenticationEligibility $policy): int
    {
        if ($this->option('execute') && ! $this->option('revoke')) {
            $this->error('--execute requires --revoke.');

            return self::FAILURE;
        }
        $counts = ['examined' => 0, 'ineligible' => 0, 'none_model' => 0, 'revoked' => 0];
        try {
            foreach ([Vendor::class, VendorEmployee::class] as $class) {
                $class::withoutGlobalScopes()->whereNotNull('auth_token')->where('auth_token', '!=', '')
                    ->chunkById(max(1, min(1000, (int) $this->option('chunk'))), function ($rows) use ($class, $policy, &$counts) {
                        foreach ($rows as $principal) {
                            $employee = $class === VendorEmployee::class;
                            $vendor = $employee ? Vendor::withoutGlobalScopes()->find($principal->vendor_id) : $principal;
                            $store = Store::withoutGlobalScopes()->when($employee, fn ($q) => $q->whereKey($principal->store_id),
                                fn ($q) => $q->where('vendor_id', $principal->id))->orderBy('id')->first();
                            $decision = $policy->evaluate($vendor, $store, 'api', $employee ? $principal : null);
                            $counts['examined']++;
                            if ($store?->store_business_model === 'none') {
                                $counts['none_model']++;
                            }
                            if ($decision->eligible) {
                                continue;
                            }
                            $counts['ineligible']++;
                            $this->line(json_encode([
                                'principal_id' => $principal->id, 'type' => $employee ? 'employee' : 'owner',
                                'reason' => $decision->reason, 'business_model' => $store?->store_business_model,
                                'token_issued_at' => 'unknown: legacy tokens have no issuance timestamp',
                            ], JSON_THROW_ON_ERROR));
                            if ($this->option('execute')) {
                                // Recheck under the same store/vendor locking order used by issuance.
                                $counts['revoked'] += DB::transaction(function () use ($class, $principal, $employee, $policy) {
                                    $store = Store::withoutGlobalScopes()->when($employee, fn ($q) => $q->whereKey($principal->store_id),
                                        fn ($q) => $q->where('vendor_id', $principal->id))->orderBy('id')->lockForUpdate()->first();
                                    $vendor = Vendor::withoutGlobalScopes()->lockForUpdate()->find($employee ? $principal->vendor_id : $principal->id);
                                    $current = $employee ? VendorEmployee::withoutGlobalScopes()->lockForUpdate()->find($principal->id) : $vendor;
                                    if (! $current || $policy->evaluate($vendor, $store, 'api', $employee ? $current : null)->eligible) {
                                        return 0;
                                    }

                                    return $class::withoutGlobalScopes()->whereKey($current->id)->where('auth_token', $principal->auth_token)->update(['auth_token' => null]);
                                }, 3);
                            }
                        }
                    });
            }
        } catch (\Throwable) {
            $this->error('Vendor token audit could not complete; no token or SQL details are printed.');
            $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $this->info(json_encode($counts + ['dry_run' => ! $this->option('execute')], JSON_THROW_ON_ERROR));
        $this->line('Legacy branch provenance and token age cannot be reconstructed; none_model is current state, not proof of issuing branch.');

        return self::SUCCESS;
    }
}
