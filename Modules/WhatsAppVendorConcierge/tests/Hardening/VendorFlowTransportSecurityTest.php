<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Modules\WhatsAppVendorConcierge\app\Services\FlowEndpointCrypto;
use Modules\WhatsAppVendorConcierge\app\Services\FlowMediaService;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;

class VendorFlowTransportSecurityTest extends HardeningTestCase
{
    public function test_local_validation_rejects_the_empty_sensitive_list_rejected_by_meta(): void
    {
        $validator = app(\Modules\WhatsAppVendorConcierge\app\Services\FlowDefinitionValidator::class);
        $definition = $validator->validate();
        $this->assertArrayNotHasKey('sensitive', $definition['screens'][4]);
        $definition['screens'][4]['sensitive'] = [];
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($definition);
    }

    public function test_explicit_served_root_allows_private_sibling_but_never_served_paths(): void
    {
        $sibling = dirname(base_path()).'/private-synthetic-fixture';
        config(['filesystems.registration_public_root' => base_path()]);
        \App\Services\PrivateRegistrationStorageGuard::assertPrivate($sibling);
        $this->addToAssertionCount(1);
        foreach ([base_path().'/storage/private', $sibling] as $index => $path) {
            if ($index === 1) {
                config(['filesystems.registration_public_root' => dirname(base_path())]);
            }
            try {
                \App\Services\PrivateRegistrationStorageGuard::assertPrivate($path);
                $this->fail('Served paths must remain excluded.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Private registration data must be outside the served tree.', $error->getMessage());
            }
        }
        config(['filesystems.registration_public_root' => null]);
        $this->expectException(\RuntimeException::class);
        \App\Services\PrivateRegistrationStorageGuard::assertPrivate($sibling);
    }

    private ?string $keyPath = null;

    protected function tearDown(): void
    {
        if ($this->keyPath && is_file($this->keyPath)) {
            unlink($this->keyPath);
        }parent::tearDown();
    }

    private function envelope(array $payload): array
    {
        $private = \phpseclib3\Crypt\RSA::createKey(2048)->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        $this->keyPath = tempnam(sys_get_temp_dir(), 'flow-test-key-');
        file_put_contents($this->keyPath, $private->toString('PKCS8'));
        config(['whatsapp-vendor-flow.private_key_path' => $this->keyPath]);
        $key = random_bytes(16);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt(json_encode($payload), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return [['encrypted_flow_data' => base64_encode($cipher.$tag), 'encrypted_aes_key' => base64_encode($private->getPublicKey()->encrypt($key)), 'initial_vector' => base64_encode($iv)], $key, $iv];
    }

    public function test_real_encrypted_ping_route_and_invalid_signature_or_tag(): void
    {
        [$outer,$key,$iv] = $this->envelope(['version' => '3.0', 'action' => 'ping']);
        $body = json_encode($outer);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')];
        $r = $this->call('POST', '/webhooks/whatsapp/flow-data', [], [], [], $headers, $body)->assertOk();
        $bytes = base64_decode($r->getContent(), true);
        $plain = openssl_decrypt(substr($bytes, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, ~$iv, substr($bytes, -16));
        $this->assertSame(['data' => ['status' => 'active']], json_decode($plain, true));
        $this->call('POST', '/webhooks/whatsapp/flow-data', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'wrong'], $body)->assertUnauthorized();
        $outer['encrypted_flow_data'] = base64_encode(random_bytes(40));
        $bad = json_encode($outer);
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $bad, 'test-secret');
        $this->call('POST', '/webhooks/whatsapp/flow-data', [], [], [], $headers, $bad)->assertStatus(421);
    }

    private function flow(): VendorFlowSession
    {
        [$c,$h] = $this->application();
        Storage::fake('vendor_flow_private');

        return VendorFlowSession::create(['onboarding_session_id' => $h->id, 'contact_id' => $c->id, 'token_hash' => hash('sha256', random_bytes(32)), 'sender' => $c->whatsapp_id, 'flow_id' => '987', 'definition_version' => 'fixture', 'locale' => 'en', 'state' => 'flow_draft', 'draft' => [], 'policy_manifest' => [], 'expires_at' => now()->addHour()]);
    }

    private function encrypted(string $plain): array
    {
        $iv = random_bytes(16);
        $key = random_bytes(32);
        $hmac = random_bytes(32);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $encrypted = $cipher.substr(hash_hmac('sha256', $iv.$cipher, $hmac, true), 0, 10);

        return [$encrypted, ['media_id' => '100', 'file_name' => 'not-trusted.jpg', 'cdn_url' => 'https://mmg.whatsapp.net/fixture', 'encryption_metadata' => ['encrypted_hash' => base64_encode(hash('sha256', $encrypted, true)), 'iv' => base64_encode($iv), 'encryption_key' => base64_encode($key), 'hmac_key' => base64_encode($hmac), 'plaintext_hash' => base64_encode(hash('sha256', $plain, true))]]];
    }

    public function test_documented_media_decryption_authenticated_download_and_private_staging(): void
    {
        $s = $this->flow();
        $image = UploadedFile::fake()->image('fixture.png', 16, 16);
        [$encrypted,$attachment] = $this->encrypted(file_get_contents($image->getRealPath()));
        Http::fake(['mmg.whatsapp.net/*' => Http::response($encrypted, 200)]);
        $r = app(FlowMediaService::class)->stage($s, 'logo', [$attachment]);
        $this->assertSame('image/png', $r['mime']);
        $this->assertTrue(Storage::disk('vendor_flow_private')->exists($r['path']));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer test-token'));
        $this->assertDatabaseCount('wa_vendor_flow_media', 1);
        $this->assertStringNotContainsString('mmg.whatsapp.net', json_encode(DB::table('wa_vendor_flow_media')->first()));
    }

    public function test_plaintext_mime_spoofing_is_rejected_after_decryption(): void
    {
        $s = $this->flow();
        [$encrypted,$a] = $this->encrypted('<html>spoofed image</html>');
        Http::fake(['mmg.whatsapp.net/*' => Http::response($encrypted, 200)]);
        try {
            app(FlowMediaService::class)->stage($s, 'logo', [$a]);
            $this->fail('Spoofed image accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('JPEG', $e->getMessage());
        }
        $this->assertDatabaseCount('wa_vendor_flow_media', 0);
        $this->assertSame([], Storage::disk('vendor_flow_private')->allFiles());
    }

    public function test_partial_ciphertext_oversized_media_and_unsafe_origins_are_rejected(): void
    {
        $s = $this->flow();
        [$encrypted,$a] = $this->encrypted(str_repeat('a', 128));
        config(['whatsapp-vendor-flow.max_image_bytes' => 32]);
        $fixtureBytes = $encrypted;
        Http::fake(function () use (&$fixtureBytes) {
            return Http::response($fixtureBytes, 200);
        });
        try {
            app(FlowMediaService::class)->stage($s, 'logo', [$a]);
            $this->fail('Oversized media accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('size', $e->getMessage());
        }
        config(['whatsapp-vendor-flow.max_image_bytes' => 2097152]);
        $fixtureBytes = substr($encrypted, 0, -5);
        try {
            app(FlowMediaService::class)->stage($s, 'logo', [$a]);
            $this->fail('Partial media accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('integrity', $e->getMessage());
        }
        $a['cdn_url'] = 'https://127.0.0.1/private';
        $this->expectException(\InvalidArgumentException::class);
        app(FlowMediaService::class)->stage($s, 'logo', [$a]);
    }

    public function test_download_failures_have_bounded_retries_and_no_partial_files(): void
    {
        $s = $this->flow();
        [, $a] = $this->encrypted('fixture');
        Http::fake(['mmg.whatsapp.net/*' => Http::response('', 404)]);
        try {
            app(FlowMediaService::class)->stage($s, 'logo', [$a]);
            $this->fail('Failed download accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Meta media download failed.', $e->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertSame([], Storage::disk('vendor_flow_private')->allFiles());
        $this->assertDatabaseCount('wa_vendor_flow_media', 0);
    }

    public function test_image_dimensions_are_bounded_before_decode(): void
    {
        $s = $this->flow();
        $image = UploadedFile::fake()->image('fixture.png', 20, 20);
        [$encrypted,$a] = $this->encrypted(file_get_contents($image->getRealPath()));
        Http::fake(['mmg.whatsapp.net/*' => Http::response($encrypted, 200)]);
        config(['whatsapp-vendor-flow.max_pixels' => 100]);
        $this->expectException(\InvalidArgumentException::class);
        app(FlowMediaService::class)->stage($s, 'logo', [$a]);
    }

    public function test_private_directory_guard_resolves_traversal_and_rejects_served_parent(): void
    {
        foreach ([base_path('private-media'), base_path('not-created/../private-media'), dirname(base_path()).'/other-site/private'] as $path) {
            try {
                \App\Services\PrivateRegistrationStorageGuard::assertPrivate($path);
                $this->fail('Served path must be rejected.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Private registration data must be outside the served tree.', $e->getMessage());
            }
        }
        \App\Services\PrivateRegistrationStorageGuard::assertPrivate(sys_get_temp_dir().'/mytijaara-safe-private-fixture');
        $this->addToAssertionCount(1);
    }
}
