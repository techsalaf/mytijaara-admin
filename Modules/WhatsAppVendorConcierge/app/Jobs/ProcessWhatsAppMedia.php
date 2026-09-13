<?php

namespace Modules\WhatsAppVendorConcierge\app\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;

class ProcessWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(
        public WhatsAppMedia $media,
        mixed $legacyGateway = null
    ) {
        // Do not store $legacyGateway to prevent Closure serialization errors
    }

    public function handle(WhatsAppGateway $gateway): void
    {
        try {
            $this->media->refresh();

            if ($this->media->status !== 'pending_download') {
                Log::info('Media already processed or not pending', [
                    'media_id' => $this->media->id,
                    'status' => $this->media->status,
                ]);
                return;
            }

            $declaredSize = (int) ($this->media->file_size ?? 0);
            if ($declaredSize > config('whatsapp-vendor-concierge.media.max_file_size')) {
                $this->media->markFailed('Media exceeds the configured size limit');
                return;
            }

            // Get media URL from Meta
            $urlResponse = $gateway->getMediaUrl($this->media->whatsapp_media_id);

            if (isset($urlResponse['error'])) {
                $this->media->markFailed('Failed to get media URL: ' . $urlResponse['error']);
                return;
            }

            $mediaUrl = $urlResponse['url'] ?? null;

            if (!$mediaUrl) {
                $this->media->markFailed('No media URL in response');
                return;
            }

            // Download media
            $filePath = $gateway->downloadMedia($mediaUrl, $this->media->whatsapp_media_id);

            if (!$filePath) {
                $this->media->markFailed('Failed to download media');
                return;
            }

            // Mark as downloaded
            $this->media->markDownloaded(
                $filePath,
                config('whatsapp-vendor-concierge.media.storage_disk', 'public')
            );

            // Update mime type if we can detect it
            if ($this->media->mime_type === 'unknown') {
                $mimeType = Storage::disk($this->media->storage_disk)->mimeType($filePath);
                $this->media->update(['mime_type' => $mimeType]);
            }

            $disk = Storage::disk($this->media->storage_disk);
            $actualSize = $disk->size($filePath);
            $actualMime = $disk->mimeType($filePath);
            if ($actualSize > config('whatsapp-vendor-concierge.media.max_file_size')
                || !in_array($actualMime, config('whatsapp-vendor-concierge.media.allowed_mime_types'), true)) {
                $disk->delete($filePath);
                $this->media->markFailed('Media type or size is not allowed');
                return;
            }
            $this->media->update(['file_size' => $actualSize, 'mime_type' => $actualMime]);

            Log::info('WhatsApp media downloaded successfully', [
                'media_id' => $this->media->id,
                'mime_type' => $actualMime,
            ]);

            // If it's a document, queue OCR processing
            if (str_starts_with($this->media->mime_type, 'application/') ||
                str_starts_with($this->media->mime_type, 'image/')) {
                ProcessVendorDocument::dispatch($this->media)
                    ->onQueue(config('whatsapp-vendor-concierge.queue.jobs.process_document'));
            }

        } catch (\Throwable $e) {
            Log::error('ProcessWhatsAppMedia failed', [
                'media_id' => $this->media->id,
                'exception' => get_class($e),
            ]);
            $this->media->markFailed($e->getMessage());
            throw $e;
        }
    }
}
