<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecureCredentialPage
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(app()->environment('production') && !$request->isSecure(), 400, 'HTTPS is required.');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
