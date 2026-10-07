<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use App\Services\VendorAuthenticationEligibility;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class VendorTokenIsValid
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $this->authorize($request, $next);
        } catch (QueryException) {
            // Legacy bearer storage uses plaintext; SQL exceptions include bindings.
            Log::notice('vendor_authorization_storage_failure');

            return response()->json(['errors' => [['code' => 'vendor_access_unavailable', 'message' => translate('Vendor access is temporarily unavailable.')]]], 503);
        }
    }

    private function authorize(Request $request, Closure $next)
    {
        $token = (string) $request->bearerToken();
        $type = $request->header('vendorType');
        if (strlen($token) !== 120 || ! in_array($type, ['owner', 'employee'], true)) {
            return $this->deny('token_scope_invalid');
        }
        $class = $type === 'owner' ? Vendor::class : VendorEmployee::class;
        $principal = $class::withoutGlobalScopes()->where('auth_token', $token)->first();
        if (! $principal) {
            return $this->deny('auth-001');
        }
        $vendor = $type === 'owner' ? $principal : Vendor::withoutGlobalScopes()->find($principal->vendor_id);
        $store = Store::withoutGlobalScopes()->when($type === 'owner', fn ($q) => $q->where('vendor_id', $principal->id),
            fn ($q) => $q->whereKey($principal->store_id))->orderBy('id')->first();
        $decision = app(VendorAuthenticationEligibility::class)->evaluate($vendor, $store, 'api', $type === 'employee' ? $principal : null);
        if (! $decision->eligible) {
            $class::withoutGlobalScopes()->whereKey($principal->id)->where('auth_token', $token)->update(['auth_token' => null]);
            Log::notice('vendor_access_denied', ['principal_id' => $principal->id, 'type' => $type, 'reason' => $decision->reason]);

            return $this->deny($decision->reason);
        }
        $vendor->setRelation('stores', new Collection([$store]));
        $vendor->setRelation('store', $store);
        $request->merge(['vendor' => $vendor]);
        if ($type === 'employee') {
            $request->merge(['vendor_employee' => $principal]);
        }
        Config::set('module.current_module_data', $store->module);

        return $next($request);
    }

    private function deny(string $code)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => translate('Vendor access is unavailable. Please sign in or contact support.')]]], 401);
    }
}
