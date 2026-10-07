<?php

namespace App\Http\Middleware;

use App\CentralLogics\Helpers;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorEmployee;
use App\Services\VendorAuthenticationEligibility;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard('vendor')->check()) {
            $vendor = Vendor::withoutGlobalScopes()->find(auth('vendor')->id());
            $store = $vendor?->stores()->withoutGlobalScopes()->orderBy('id')->first();
            if (! app(VendorAuthenticationEligibility::class)->evaluate($vendor, $store, 'web')->eligible) {
                auth()->guard('vendor')->logout();

                return redirect()->route('home');
            }

            if (session('login_remember_token') !== $vendor?->login_remember_token) {
                auth()->guard('vendor')->logout();
                session()->invalidate();
                session()->regenerateToken();
                $user_link = Helpers::get_login_url('store_login_url');

                return redirect()->route('login', [$user_link])
                    ->withErrors(['Your session has expired. Please log in again.']);
            }

            return $next($request);
        } elseif (Auth::guard('vendor_employee')->check()) {
            if (Auth::guard('vendor_employee')->user()->is_logged_in == 0) {
                auth()->guard('vendor_employee')->logout();

                return redirect()->route('home');
            }
            $employee = VendorEmployee::withoutGlobalScopes()->find(auth('vendor_employee')->id());
            $vendor = $employee ? Vendor::withoutGlobalScopes()->find($employee->vendor_id) : null;
            $store = $employee ? Store::withoutGlobalScopes()->find($employee->store_id) : null;
            if (! app(VendorAuthenticationEligibility::class)->evaluate($vendor, $store, 'web', $employee)->eligible) {
                auth()->guard('vendor_employee')->logout();

                return redirect()->route('home');
            }

            if (session('login_remember_token') !== $employee?->login_remember_token) {
                auth()->guard('vendor_employee')->logout();
                session()->invalidate();
                session()->regenerateToken();
                $user_link = Helpers::get_login_url('store_employee_login_url');

                return redirect()->route('login', [$user_link])
                    ->withErrors(['Your session has expired. Please log in again.']);
            }

            return $next($request);
        }

        return redirect()->route('home');
    }
}
