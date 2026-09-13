<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Middleware;

use App\CentralLogics\Helpers;
use Closure;
use Illuminate\Http\Request;

class AuthorizeWhatsAppOperations
{
    public function handle(Request $request, Closure $next)
    {
        $admin = auth('admin')->user();
        if (!$admin || !$admin->is_logged_in
            || $request->session()->get('login_remember_token') !== $admin->login_remember_token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Reuse assignable core permissions: access covers both vendor applications
        // and private conversations. Customer Passport tokens cannot grant access.
        if (!Helpers::module_permission_check('store') || !Helpers::module_permission_check('contact_messages')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return $next($request);
    }
}
