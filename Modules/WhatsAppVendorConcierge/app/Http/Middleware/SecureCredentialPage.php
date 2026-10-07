<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecureCredentialPage
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        abort_if(! app()->environment(['local', 'testing']) && ! $request->isSecure(), 400, 'HTTPS is required.');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
