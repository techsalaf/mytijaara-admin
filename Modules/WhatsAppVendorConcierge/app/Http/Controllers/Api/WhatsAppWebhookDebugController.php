<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppWebhookDebugController extends Controller
{
    /**
     * Test and debug HMAC-SHA256 signature calculation.
     */
    public function testSignature(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signatureHeader = $request->header(config('whatsapp-vendor-concierge.webhook.signature_header', 'X-Hub-Signature-256'));
        $appSecret = config('whatsapp-vendor-concierge.api.app_secret');

        if (!$appSecret) {
            return response()->json([
                'error' => 'App secret is not configured in .env (WHATSAPP_APP_SECRET)',
            ], 500);
        }

        $expectedHash = hash_hmac('sha256', $payload, $appSecret);
        $expectedSignature = 'sha256=' . $expectedHash;

        $isValid = false;
        if ($signatureHeader && str_starts_with($signatureHeader, 'sha256=')) {
            $providedHash = substr($signatureHeader, 7);
            $isValid = hash_equals($expectedHash, $providedHash);
        }

        return response()->json([
            'is_valid' => $isValid,
            'header_received' => $signatureHeader,
            'expected_signature' => $expectedSignature,
            'payload_length_bytes' => strlen($payload),
            'app_secret_configured' => true,
        ]);
    }
}
