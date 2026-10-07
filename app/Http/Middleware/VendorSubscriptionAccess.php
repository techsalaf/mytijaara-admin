<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\Vendor;
use App\Services\VendorAuthenticationEligibility;
use App\Services\VendorSecurityTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorSubscriptionAccess
{
    // Explicit route and HTTP-method allowlist. Restricted tokens never use vendor.api.
    private const ROUTES = [
        'POST api/v1/vendor/business_plan',
    ];

    public function handle(Request $request, Closure $next)
    {
        $raw = (string) $request->bearerToken();
        $request->attributes->remove('vendor_restricted');
        if (strlen($raw) === 120) {
            return app(VendorTokenIsValid::class)->handle($request, function ($request) use ($next) {
                if ($request->header('vendorType') !== 'owner'
                    || ! $request->vendor->stores()->whereKey($request->input('store_id'))->exists()) {
                    return $this->deny('token_scope_invalid');
                }

                return $next($request);
            });
        }
        if (strlen($raw) !== 64 || $request->header('vendorType') !== 'owner'
            || ! in_array($request->method().' '.$request->path(), self::ROUTES, true)) {
            return $this->deny('token_scope_invalid');
        }
        $record = app(VendorSecurityTokenService::class)->lookup($raw, VendorSecurityTokenService::PRE_ACTIVATION);
        if (! $record) {
            return $this->deny('token_expired');
        }

        return DB::transaction(function () use ($request, $next, $record) {
            $store = Store::withoutGlobalScopes()->lockForUpdate()->find($record->store_id);
            $vendor = Vendor::withoutGlobalScopes()->lockForUpdate()->find($record->vendor_id);
            $token = DB::table('vendor_security_tokens')->where('id', $record->id)->lockForUpdate()->first();
            if (! $token || $token->consumed_at || $token->revoked_at || $token->expires_at <= now()->toDateTimeString()) {
                return $this->deny('token_expired');
            }
            if (! $vendor || ! app(VendorSecurityTokenService::class)->matchesSubject($token, $vendor)) {
                return $this->deny('token_scope_invalid');
            }
            $decision = app(VendorAuthenticationEligibility::class)->evaluate($vendor, $store);
            if (! $decision->preActivationAllowed || ! $store || (string) $request->input('store_id') !== (string) $store->id) {
                DB::table('vendor_security_tokens')->where('id', $record->id)->update(['revoked_at' => now()]);

                return $this->deny('token_scope_invalid');
            }
            $request->merge(['vendor' => $vendor]);
            $request->attributes->set('vendor_restricted', true);
            $response = $next($request);
            // A successful POST consumes authorization, including payment initiation.
            // Provider completion cannot exchange this token for full access.
            if ($request->isMethod('POST') && $response->getStatusCode() < 300) {
                DB::table('vendor_security_tokens')->where('id', $record->id)->update(['consumed_at' => now()]);
            }

            return $response;
        }, 3);
    }

    private function deny(string $code)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => translate('Subscription setup authorization is unavailable. Please sign in again.')]]], 403);
    }
}
