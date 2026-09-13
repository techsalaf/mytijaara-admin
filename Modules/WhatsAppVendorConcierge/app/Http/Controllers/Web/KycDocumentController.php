<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;

class KycDocumentController extends Controller
{
    /**
     * Authorized KYC Document Retrieval with access audit logging.
     * Prevents unauthenticated, predictable public access to sensitive vendor identity files.
     */
    public function show(Request $request, WhatsAppMedia $media)
    {
        // 1. Authorize: Must be authenticated admin session or signed URL
        $adminId = auth('admin')->id();
        $isSigned = $request->hasValidSignature();

        if (!$adminId && !$isSigned) {
            abort(403, 'Unauthorized access to KYC documents.');
        }

        // 2. Validate media status & storage
        $diskName = $media->storage_disk ?: config('whatsapp-vendor-concierge.media.storage_disk', 'local');
        abort_unless($media->file_path && in_array($media->status, ['downloaded', 'processed'], true), 404);
        abort_unless(Storage::disk($diskName)->exists($media->file_path), 404);

        // 3. Audit access log
        Log::info('KYC document accessed by authorized user', [
            'media_id' => $media->id,
            'admin_id' => $adminId,
            'is_signed_url' => $isSigned,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);

        $ext = pathinfo($media->file_path, PATHINFO_EXTENSION) ?: 'bin';
        $downloadName = 'kyc-doc-' . $media->id . '.' . $ext;

        return Storage::disk($diskName)->download($media->file_path, $downloadName, [
            'Cache-Control' => 'no-store, no-cache, private, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
