<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\FlowControl;

use App\Services\PrivateRegistrationStorageGuard;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;

class EndpointProbe
{
    public function key(): PrivateKey
    {
        $path = (string) config('whatsapp-vendor-flow.private_key_path');
        PrivateRegistrationStorageGuard::assertPrivate(dirname($path));
        if (! is_file($path) || filesize($path) > 65536) {
            throw new Failure('Configured private encryption key unavailable.');
        }
        $key = RSA::loadPrivateKey(file_get_contents($path), config('whatsapp-vendor-flow.private_key_passphrase') ?: false);

        return $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
    }

    public function run(): array
    {
        $url = (string) config('whatsapp-vendor-flow.endpoint_url');
        $expected = rtrim((string) config('app.url'), '/').'/webhooks/whatsapp/flow-data';
        if ($url !== $expected || ! str_starts_with($url, 'https://')) {
            throw new Failure('Probe only supports the configured same-application HTTPS endpoint.');
        }
        $key = random_bytes(16);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt(json_encode(['version' => '3.0', 'action' => 'ping']), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        $body = json_encode(['encrypted_flow_data' => base64_encode($cipher.$tag), 'encrypted_aes_key' => base64_encode($this->key()->getPublicKey()->encrypt($key)), 'initial_vector' => base64_encode($iv)], JSON_THROW_ON_ERROR);
        $secret = (string) config('whatsapp-vendor-concierge.api.app_secret');
        if (! $secret) {
            throw new Failure('Webhook signing credential unavailable.');
        }
        $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)])->withBody($body, 'application/json')->post($url);
        if (! $response->successful() || strlen($response->body()) > 4096) {
            throw new Failure('Signed endpoint ping failed (HTTP '.$response->status().').');
        }
        $bytes = base64_decode($response->body(), true);
        if ($bytes === false || strlen($bytes) < 16) {
            throw new Failure('Endpoint ping response is malformed.');
        }
        $plain = openssl_decrypt(substr($bytes, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, ~$iv, substr($bytes, -16));
        $active = $plain !== false && (json_decode($plain, true)['data']['status'] ?? null) === 'active';
        if (! $active) {
            throw new Failure('Endpoint ping failed authenticated response validation.');
        }

        return ['endpoint' => 'active', 'http_status' => 200, 'encrypted_contract' => 'verified', 'signature' => 'verified'];
    }
}
