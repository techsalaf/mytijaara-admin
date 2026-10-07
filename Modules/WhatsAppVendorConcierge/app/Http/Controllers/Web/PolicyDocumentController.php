<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Models\LegalPolicyVersion;
use Illuminate\Support\Facades\Storage;

final class PolicyDocumentController
{
    public function __invoke(string $version)
    {
        $policy = LegalPolicyVersion::where('version', $version)->where('locale', 'en')->firstOrFail();
        $object = $policy->storage_object;
        abort_unless(is_string($object) && preg_match('~\Apolicy-documents/[a-zA-Z0-9_-]+\.html\z~D', $object), 404);
        try {
            $disk = config('registration-policies.archive_disk');
            abort_unless(is_string($disk) && $disk !== '', 503);
            $size = Storage::disk($disk)->size($object);
            abort_unless($size > 0 && $size <= (int) config('registration-policies.archive_max_bytes', 4194304), 503);
            $bytes = Storage::disk($disk)->get($object);
            abort_unless(hash_equals($policy->content_hash, hash('sha256', $bytes)), 503);
        } catch (\Throwable) {
            abort(503, 'Policy document unavailable.');
        }

        return response($bytes, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; sandbox; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$policy->content_hash.'"',
        ]);
    }
}
