<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use phpseclib3\Crypt\RSA;

class FlowEndpointCrypto
{
    public function decrypt(array $outer): array
    {
        $keyPath = (string) config('whatsapp-vendor-flow.private_key_path');
        if (! $keyPath || ! is_file($keyPath)) {
            throw new \RuntimeException('Flow encryption key unavailable.');
        }
        if (! app()->environment('testing')) {
            \App\Services\PrivateRegistrationStorageGuard::assertPrivate(dirname($keyPath));
        }
        $rsa = RSA::loadPrivateKey(file_get_contents($keyPath), config('whatsapp-vendor-flow.private_key_passphrase') ?: false)->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        $key = $rsa->decrypt($this->decode($outer['encrypted_aes_key'] ?? ''));
        $iv = $this->decode($outer['initial_vector'] ?? '');
        $cipher = $this->decode($outer['encrypted_flow_data'] ?? '');
        if (strlen($key) !== 16 || strlen($iv) !== 16 || strlen($cipher) < 17 || strlen($cipher) > 262144) {
            throw new \RuntimeException('Invalid encrypted envelope.');
        }
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($cipher, -16));
        if ($plain === false) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }
        $data = json_decode($plain, true, 20, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['version'] ?? '') !== '3.0') {
            throw new \RuntimeException('Unsupported endpoint protocol.');
        }

        return [$data, $key, $iv];
    }

    public function encrypt(array $response, string $key, string $iv): string
    {
        $cipher = openssl_encrypt(json_encode($response, JSON_THROW_ON_ERROR), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, ~$iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Response encryption failed.');
        }

        return base64_encode($cipher.$tag);
    }

    private function decode(string $value): string
    {
        $v = base64_decode($value, true);
        if ($v === false) {
            throw new \RuntimeException('Invalid ciphertext encoding.');
        }

        return $v;
    }
}
