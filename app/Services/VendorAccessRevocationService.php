<?php

namespace App\Services;

use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VendorAccessRevocationService
{
    /** Caller holds store/vendor locks and saves the vendor in the same transaction. */
    public function invalidate(Vendor $vendor): void
    {
        // Attribute presence supports old installations without optional session columns.
        foreach (['auth_token', 'remember_token', 'login_remember_token'] as $field) {
            if (array_key_exists($field, $vendor->getAttributes())) {
                $vendor->setAttribute($field, $field === 'auth_token' ? null : bin2hex(random_bytes(30)));
            }
        }
        if (Schema::hasTable('vendor_employees')) {
            DB::table('vendor_employees')->where('vendor_id', $vendor->id)->update(['auth_token' => null]);
            if (Schema::hasColumn('vendor_employees', 'login_remember_token')) {
                DB::table('vendor_employees')->where('vendor_id', $vendor->id)->update(['login_remember_token' => bin2hex(random_bytes(30))]);
            }
        }
        if (Schema::hasTable('vendor_security_tokens')) {
            DB::table('vendor_security_tokens')->where('vendor_id', $vendor->id)->whereNull('consumed_at')
                ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        }
    }
}
