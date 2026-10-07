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
    public function verify(Request $request)
    {
        // PHP converts dots to underscores in query params: hub.mode -> hub_mode, but let's check both
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $expectedToken = config('whatsapp-vendor-concierge.webhook.verify_token');

        Log::info('WhatsApp webhook verification attempt', [
            'mode' => $mode,
            'token_provided' => $token !== null,
            'token_match' => $token === $expectedToken,
            'expected_token_length' => $expectedToken ? strlen($expectedToken) : 0,
            'provided_token_length' => $token ? strlen($token) : 0,
        ]);

        if ($mode === 'subscribe' && is_string($expectedToken) && $expectedToken !== ''
            && is_string($token) && hash_equals($expectedToken, $token)) {
            Log::info('WhatsApp webhook verified successfully');
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'expected_token_matches' => $token === $expectedToken,
        ]);

        return response()->json(['error' => 'Forbidden'], 403);
    }

    /**
     * POST /webhooks/whatsapp - Handle incoming webhook events
     */
    public function handle(Request $request): JsonResponse
    {
        if (app()->bound('debugbar')) app('debugbar')->disable();
        // Validate signature if enabled
        if (config('whatsapp-vendor-concierge.webhook.enable_signature_validation')) {
            if (! $this->validateSignature($request)) {
                Log::warning('WhatsApp webhook signature validation failed', [
                    'ip' => $request->ip(),
                    'signature_provided' => $request->hasHeader(config('whatsapp-vendor-concierge.webhook.signature_header')),
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
                        if (($message['interactive']['type'] ?? '') === 'nfm_reply' || isset($message['interactive']['nfm_reply'])) {
                            // Completion responses bypass conversation storage, AI and generic message jobs.
                            if (! $this->validateSignature($request)) {
                                return response()->json(['error' => 'Invalid signature'], 401);
                            }
                            if ((string) ($value['metadata']['phone_number_id'] ?? '') !== (string) config('whatsapp-vendor-concierge.api.phone_number_id')
                                || (string) ($entry['id'] ?? '') !== (string) config('whatsapp-vendor-concierge.api.business_account_id')) {
                                continue;
                            }
                            try {
                                $submission = \Modules\WhatsAppVendorConcierge\app\DTOs\FlowSubmission::fromMessage($message);
                                \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessVendorFlowSubmission::dispatch($submission);
                            } catch (\JsonException|\InvalidArgumentException $error) {
                                Log::notice('Malformed Flow completion rejected', ['message_id' => $message['id'] ?? null]);
                            }

                            continue;
                        }
                        $message = \Modules\WhatsAppVendorConcierge\app\Services\InboundPrivacy::redact($message);
                        $metaContext = array_intersect_key($value, array_flip(['contacts', 'metadata']));
                        if (app()->environment('testing')) {
                            ProcessIncomingWhatsAppMessage::dispatch($message, $metaContext);
                        } else {
                            try {
                                ProcessIncomingWhatsAppMessage::dispatchSync($message, $metaContext);
                            } catch (\Throwable $e) {
                                Log::error('Synchronous WhatsApp message processing failed, falling back to queue', [
                                    'message_id' => $message['id'] ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                                ProcessIncomingWhatsAppMessage::dispatch($message, $metaContext);
                            }
                        }
                    }

                    // Process status updates async
                    foreach ($value['statuses'] ?? [] as $status) {
                        \Modules\WhatsAppVendorConcierge\app\Jobs\ProcessWhatsAppStatus::dispatchAfterResponse($status);
                    }

                    // Process flow responses async
                    // Only documented interactive.nfm_reply completion messages are accepted.
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


