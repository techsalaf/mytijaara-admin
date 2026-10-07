<?php

namespace App\Services;

use App\DTOs\RegistrationPolicyEvidence;
use App\Models\LegalPolicyVersion;
use App\Models\VendorRegistrationConsent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RegistrationPolicyService
{
    public const TERMS = 'I agree to the terms of service.';

    public const PRIVACY = 'I acknowledge the privacy notice.';

    public function evidence(array $values): ?RegistrationPolicyEvidence
    {
        if (! array_intersect(['policy_locale', 'terms_version', 'privacy_version', 'presentation_hash'], array_keys($values))) {
            return null;
        }
        validator($values, ['policy_locale' => 'required|string|max:20', 'terms_version' => 'required|string|max:191', 'privacy_version' => 'required|string|max:191', 'presentation_hash' => 'required|string|size:64'])->validate();

        return new RegistrationPolicyEvidence($values['policy_locale'], $values['terms_version'], $values['privacy_version'], $values['presentation_hash']);
    }

    public function manifest(string $locale): array
    {
        $out = ['locale' => $locale];
        foreach (['terms', 'privacy'] as $kind) {
            $version = config('registration-policies.current.'.$locale.'.'.$kind);
            $row = LegalPolicyVersion::where('policy', $kind)->where('version', $version)->where('locale', $locale)
                ->where('effective_at', '<=', now('UTC'))->whereNull('retired_at')->first();
            if (! $row || ! preg_match('/^[a-f0-9]{64}$/', $row->content_hash) || ! str_starts_with($row->document_url, 'https://') || ! $row->storage_object) {
                throw ValidationException::withMessages(['policy' => 'The current policy documents are unavailable.']);
            }
            $this->verifyArchive($row);
            $out[$kind] = ['id' => $row->id, 'version' => $row->version, 'url' => $row->document_url, 'hash' => $row->content_hash];
        }
        $out['terms_statement'] = self::TERMS;
        $out['privacy_statement'] = self::PRIVACY;
        $out['presentation_hash'] = hash('sha256', json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    private function verifyArchive(LegalPolicyVersion $row): void
    {
        $stream = null;
        try {
            $disk = config('registration-policies.archive_disk');
            $object = $row->storage_object;
            if (! is_string($disk) || $disk === '' || ! is_string($object)
                || ! preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9/_\.\-]*\z~D', $object)
                || in_array('..', explode('/', $object), true)) {
                throw new \RuntimeException('Policy archive reference unavailable.');
            }
            $stream = Storage::disk($disk)->readStream($object);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Policy archive unavailable.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            $limit = (int) config('registration-policies.archive_max_bytes', 4194304);
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new \RuntimeException('Policy archive read failed.');
                }
                $bytes += strlen($chunk);
                if ($limit <= 0 || $bytes > $limit) {
                    throw new \RuntimeException('Policy archive exceeds reviewed limit.');
                }
                hash_update($hash, $chunk);
            }
            if ($bytes === 0 || ! hash_equals($row->content_hash, hash_final($hash))) {
                throw new \RuntimeException('Policy archive integrity failure.');
            }
        } catch (\Throwable) {
            // Never expose object references, document bytes or underlying storage errors.
            throw ValidationException::withMessages(['policy' => 'The current policy documents are unavailable.']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function verify(RegistrationPolicyEvidence $evidence): array
    {
        $m = $this->manifest($evidence->locale);
        if ($m['terms']['version'] !== $evidence->termsVersion || $m['privacy']['version'] !== $evidence->privacyVersion || ! hash_equals($m['presentation_hash'], $evidence->presentationHash)) {
            throw ValidationException::withMessages(['policy' => 'Review the current policy versions and agree again.']);
        }

        return $m;
    }

    public function record(int $vendorId, int $storeId, RegistrationPolicyEvidence $evidence, string $source): void
    {
        $m = $this->verify($evidence);
        foreach (['terms' => 'terms_agreement', 'privacy' => 'privacy_notice_acknowledgment'] as $kind => $meaning) {
            VendorRegistrationConsent::create(['vendor_id' => $vendorId, 'store_id' => $storeId, 'legal_policy_version_id' => $m[$kind]['id'],
                'locale' => $evidence->locale, 'evidence_kind' => $meaning, 'presentation_hash' => $evidence->presentationHash,
                'accepted_at' => now('UTC'), 'created_at' => now('UTC'), 'source' => $source]);
        }
    }
}
