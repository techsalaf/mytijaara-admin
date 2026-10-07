<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;

class FlowMediaService
{
    public function stage(VendorFlowSession $session, string $role, array $attachments): array
    {
        if (! in_array($role, ['logo', 'cover', 'tin_document'], true) || count($attachments) !== 1) {
            throw new \InvalidArgumentException('Select exactly one supported file.');
        }
        $a = $attachments[0];
        if (! is_array($a) || ! is_string($a['media_id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid media metadata.');
        }
        $id = (string) ($a['media_id'] ?? '');
        if (! preg_match('/^[0-9]+$/D', $id)) {
            throw new \InvalidArgumentException('Invalid media identifier.');
        }
        $identity = hash('sha256', $id);
        $existing = DB::table('wa_vendor_flow_media')->where('media_identity', $identity)->first();
        if ($existing) {
            if ($existing->flow_session_id != $session->id || $existing->role !== $role || ! in_array($existing->state, ['staged', 'superseded'], true)) {
                throw new \InvalidArgumentException('Media belongs to another attempt.');
            }

            $disk = Storage::disk('vendor_flow_private');
            if (! $disk->exists($existing->path) || ! hash_equals($existing->sha256, hash('sha256', $disk->get($existing->path)))) {
                throw new \RuntimeException('Staged media integrity failed.');
            }
            $this->select($session, $role, $existing->id);

            return (array) $existing;
        }
        $bytes = $this->downloadEncrypted($a);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new \InvalidArgumentException('Use a JPEG or PNG image.');
        }
        $info = @getimagesizefromstring($bytes);
        $max = (int) config('whatsapp-vendor-flow.max_dimension', 6000);
        $pixels = (int) config('whatsapp-vendor-flow.max_pixels', 16000000);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] > $max || $info[1] > $max || $pixels < $info[0] * $info[1] || ($info['bits'] ?? 8) > 8) {
            throw new \InvalidArgumentException('Image dimensions are unsupported.');
        }
        // Fully decode bounded images; strips metadata and rejects truncated/image-bomb payloads.
        $memoryLimit = trim((string) ini_get('memory_limit'));
        if ($memoryLimit !== '' && $memoryLimit !== '-1') {
            $factor = match (strtolower(substr($memoryLimit, -1))) {
                'g' => 1073741824,'m' => 1048576,'k' => 1024,default => 1
            };
            $budget = (int) $memoryLimit * $factor;
            if (memory_get_usage(true) + $info[0] * $info[1] * 16 + 8388608 > $budget * 0.8) {
                throw new \InvalidArgumentException('Image exceeds safe decoding memory budget.');
            }
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw new \InvalidArgumentException('Image content is invalid.');
        }
        ob_start();
        $ok = $mime === 'image/png' ? imagepng($image) : imagejpeg($image, null, 90);
        $clean = ob_get_clean();
        imagedestroy($image);
        if (! $ok || ! $clean || strlen($clean) > $this->limit()) {
            throw new \InvalidArgumentException('Image cannot be safely processed.');
        }
        $path = 'sessions/'.$session->id.'/'.bin2hex(random_bytes(24)).($mime === 'image/png' ? '.png' : '.jpg');
        $disk = Storage::disk('vendor_flow_private');
        if (! app()->environment('testing')) {
            \App\Services\PrivateRegistrationStorageGuard::assertPrivate($disk->path(''));
        }
        if (! $disk->put($path, $clean, ['visibility' => 'private'])) {
            throw new \RuntimeException('Private media staging failed.');
        }
        try {
            $row = ['flow_session_id' => $session->id, 'role' => $role, 'media_identity' => $identity, 'path' => $path, 'mime' => $mime, 'sha256' => hash('sha256', $clean), 'bytes' => strlen($clean), 'state' => 'staged', 'created_at' => now(), 'updated_at' => now()];
            $row['id'] = DB::table('wa_vendor_flow_media')->insertGetId($row);
            $this->select($session, $role, $row['id']);

            return $row;
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    public function file(VendorFlowSession $s, string $role): ?UploadedFile
    {
        $r = DB::table('wa_vendor_flow_media')->where('flow_session_id', $s->id)->where('role', $role)->where('state', 'staged')->latest('id')->first();
        if (! $r) {
            return null;
        }
        $path = Storage::disk('vendor_flow_private')->path($r->path);
        if (! is_file($path) || ! hash_equals($r->sha256, hash_file('sha256', $path))) {
            throw new \RuntimeException('Staged media integrity failed.');
        }

        return new UploadedFile($path, basename($path), $r->mime, null, true);
    }

    protected function downloadEncrypted(array $a): string
    {
        if (! is_string($a['cdn_url'] ?? null) || ! is_array($a['encryption_metadata'] ?? null)) {
            throw new \InvalidArgumentException('Invalid media metadata.');
        }
        $url = (string) ($a['cdn_url'] ?? '');
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower($parts['host'] ?? ''), ['mmg.whatsapp.net', 'scontent.whatsapp.net'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new \InvalidArgumentException('Unsupported Meta media origin.');
        }
        $limit = $this->limit() + 64;
        $encrypted = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = \Illuminate\Support\Facades\Http::withToken((string) config('whatsapp-vendor-concierge.api.access_token'))->connectTimeout(5)->timeout(15)->withOptions(['stream' => true, 'read_timeout' => 15, 'allow_redirects' => false])->get($url);
                $res = $response->toPsrResponse();
                if ($res->getStatusCode() !== 200) {
                    throw new \RuntimeException('Meta media download failed.');
                }
                if ((int) $res->getHeaderLine('Content-Length') > $limit) {
                    throw new \InvalidArgumentException('Media exceeds size limit.');
                }
                $encrypted = '';
                $body = $res->getBody();
                while (! $body->eof()) {
                    $encrypted .= $body->read(8192);
                    if (strlen($encrypted) > $limit) {
                        $body->close();
                        throw new \InvalidArgumentException('Media exceeds size limit.');
                    }
                } $body->close();
                break;
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    throw new \RuntimeException('Meta media download failed.');
                }
            }
        }
        $m = $a['encryption_metadata'] ?? [];
        $values = [];
        foreach (['encrypted_hash', 'iv', 'encryption_key', 'hmac_key', 'plaintext_hash'] as $name) {
            if (! is_string($m[$name] ?? null)) {
                throw new \InvalidArgumentException('Invalid media encryption metadata.');
            }
            $v = base64_decode((string) ($m[$name] ?? ''), true);
            if ($v === false || $v === '') {
                throw new \InvalidArgumentException('Invalid media encryption metadata.');
            }$values[$name] = $v;
        }
        if (strlen($encrypted) < 26 || strlen($values['iv']) !== 16 || strlen($values['encryption_key']) !== 32 || strlen($values['hmac_key']) !== 32 || ! hash_equals($values['encrypted_hash'], hash('sha256', $encrypted, true))) {
            throw new \InvalidArgumentException('Media integrity failed.');
        }
        $cipher = substr($encrypted, 0, -10);
        $mac = substr($encrypted, -10);
        if (! hash_equals($mac, substr(hash_hmac('sha256', $values['iv'].$cipher, $values['hmac_key'], true), 0, 10))) {
            throw new \InvalidArgumentException('Media authentication failed.');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $values['encryption_key'], OPENSSL_RAW_DATA, $values['iv']);
        if ($plain === false || strlen($plain) > $this->limit() || ! hash_equals($values['plaintext_hash'], hash('sha256', $plain, true))) {
            throw new \InvalidArgumentException('Media decryption failed.');
        }

        return $plain;
    }

    private function limit(): int
    {
        return min(2097152, max(1, (int) config('whatsapp-vendor-flow.max_image_bytes', 2097152)));
    }

    private function select(VendorFlowSession $session, string $role, int $mediaId): void
    {
        DB::table('wa_vendor_flow_media')->where('flow_session_id', $session->id)->where('role', $role)->where('id', '!=', $mediaId)->where('state', 'staged')->update(['state' => 'superseded', 'updated_at' => now()]);
        DB::table('wa_vendor_flow_media')->where('id', $mediaId)->where('flow_session_id', $session->id)->update(['state' => 'staged', 'updated_at' => now()]);
    }
}
