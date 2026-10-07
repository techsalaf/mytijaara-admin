<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api;

use Illuminate\Http\Request;
use Modules\WhatsAppVendorConcierge\app\Services\FlowEndpointCrypto;
use Modules\WhatsAppVendorConcierge\app\Services\FlowDataExchangeService;

class FlowEndpointController
{
    public function __invoke(Request $request, FlowEndpointCrypto $crypto, FlowDataExchangeService $exchange)
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        $secret = (string) config('whatsapp-vendor-concierge.api.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        if (! $secret || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response('', 401);
        }
        if (strlen($request->getContent()) > 350000) {
            return response('', 413);
        }
        try {
            [$data,$key,$iv] = $crypto->decrypt(json_decode($request->getContent(), true, 10, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            return response('', 421);
        }
        try {
            $out = $exchange->handle($data);

            return response($crypto->encrypt($out, $key, $iv), 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $e) {
            return response('', 400);
        } catch (\Throwable $e) {
            return response('', 503);
        }
    }
}
