<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\Vendor;
use App\Services\VendorAuthenticationEligibility;
use Closure;
use Illuminate\Http\Request;

class VendorRegistrationSession
{
    public function handle(Request $request, Closure $next)
    {
        $store = Store::withoutGlobalScopes()->find($request->input('store_id'));
        $vendor = $store ? Vendor::withoutGlobalScopes()->find($store->vendor_id) : null;
        $decision = app(VendorAuthenticationEligibility::class)->evaluate($vendor, $store, 'web');
        $owner = auth('vendor')->id() && (int) auth('vendor')->id() === (int) $vendor?->id;
        $registration = $store && (int) $request->session()->get('vendor_registration_store_id') === (int) $store->id
            && (int) $request->session()->get('vendor_registration_expires_at') > now()->timestamp;
        abort_unless($store && ($decision->eligible && $owner || $decision->preActivationAllowed && $registration), 403);

        return $next($request);
    }
}
