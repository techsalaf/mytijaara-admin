<?php
namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\CentralLogics\Helpers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;
use Modules\WhatsAppVendorConcierge\app\Services\MediaPolicyService;

/** Copy validated, owned inbound media into the host's ordinary product storage. */
class ProductMedia
{
    public function owned(int $mediaId, int $vendorId): WhatsAppMedia
    {
        $media = WhatsAppMedia::findOrFail($mediaId);
        $owned = WhatsAppMessage::where('media_id', $mediaId)->where('direction', 'inbound')
            ->whereHas('conversation.contact', fn ($q) => $q->where('vendor_id', $vendorId))->exists();
        abort_unless($owned, 403, 'This image was not received from your vendor account.');
        if (!in_array($media->status, ['downloaded', 'processed'], true) || !$media->file_path) {
            throw ValidationException::withMessages(['image' => ['The image is not ready. Please upload it again.']]);
        }
        return $media;
    }

    public function publish(int $mediaId, int $vendorId): string
    {
        $media = $this->owned($mediaId, $vendorId);
        $validation = app(MediaPolicyService::class)->validateProductImage($media);
        if (!$validation['valid']) throw ValidationException::withMessages(['image' => [$validation['error']]]);
        $temporary = tempnam(sys_get_temp_dir(), 'concierge-product-');
        if ($temporary === false) throw new \RuntimeException('Cannot prepare product image');
        try {
            $bytes = Storage::disk($media->storage_disk ?: 'public')->get($media->file_path);
            if (file_put_contents($temporary, $bytes) === false) throw new \RuntimeException('Cannot prepare product image');
            $extension = match ($validation['mime_type']) { 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'png' };
            return Helpers::upload('product/', $extension, new UploadedFile($temporary, 'product-image.'.$extension, $validation['mime_type'], null, true));
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
