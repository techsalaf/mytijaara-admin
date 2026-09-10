<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessIncomingWhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;

class WebhookController extends \App\Http\Controllers\Controller
{
    public function __construct(
        protected WhatsAppGateway $gateway
    ) {}

    /**
     * GET /webhooks/whatsapp - Meta webhook verification
     */
    public function verify(Request $request): JsonResponse
    {
        // Meta sends parameters with dots: hub.mode, hub.verify_token, hub.challenge
        $mode = $request->query('hub.mode');
        $token = $request->query('hub.verify_token');
        $challenge = $request->query('hub.challenge');

        $expectedToken = config('whatsapp-vendor-concierge.webhook.verify_token');

        Log::info('WhatsApp webhook verification attempt', [
            'mode' => $mode,
            'token_provided' => $token !== null,
            'token_match' => $token === $expectedToken,
        ]);

        if ($mode === 'subscribe' && $token === $expectedToken) {
            Log::info('WhatsApp webhook verified successfully');
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'expected_token' => $expectedToken !== null,
        ]);

        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * POST /webhooks/whatsapp - Handle incoming webhook events
     */
    public function handle(Request $request): JsonResponse
    {
        // Validate signature if enabled
        if (config('whatsapp-vendor-concierge.webhook.enable_signature_validation')) {
            if (!$this->validateSignature($request)) {
                Log::warning('WhatsApp webhook signature validation failed', [
                    'ip' => $request->ip(),
                    'signature' => $request->header(config('whatsapp-vendor-concierge.webhook.signature_header')),
                ]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        Log::info('WhatsApp webhook received', [
            'entry_count' => count($payload['entry'] ?? []),
        ]);

        // Acknowledge immediately - process async
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') === 'messages') {
                    $value = $change['value'] ?? [];

                    // Process each message async
                    foreach ($value['messages'] ?? [] as $message) {
                        ProcessIncomingWhatsAppMessage::dispatch($message, $value)
                            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming'));
                    }

                    // Process status updates async
                    foreach ($value['statuses'] ?? [] as $status) {
                        ProcessIncomingWhatsAppMessage::dispatchStatus($status, $value)
                            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming'));
                    }

                    // Process flow responses async
                    if (isset($value['flow'])) {
                        ProcessIncomingWhatsAppMessage::dispatchFlow($value['flow'], $value)
                            ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_incoming'));
                    }
                }
            }
        }

        return response()->json(['status' => 'received'], 200);
    }

    /**
     * Validate HMAC signature from Meta
     */
    protected function validateSignature(Request $request): bool
    {
        $signature = $request->header(config('whatsapp-vendor-concierge.webhook.signature_header'));
        $appSecret = config('whatsapp-vendor-concierge.api.app_secret');

        if (!$signature || !$appSecret) {
            return false;
        }

        // Signature format: "sha256=<hash>"
        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);
        $providedHash = substr($signature, 7);

        return hash_equals($expectedHash, $providedHash);
    }
}