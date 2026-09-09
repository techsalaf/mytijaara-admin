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
        public WhatsAppGateway $gateway
    ) {}

    public function handle(): void
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

            // Get media URL from Meta
            $urlResponse = $this->gateway->getMediaUrl($this->media->whatsapp_media_id);

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
            $filePath = $this->gateway->downloadMedia($mediaUrl, $this->media->whatsapp_media_id);

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

            Log::info('WhatsApp media downloaded successfully', [
                'media_id' => $this->media->id,
                'file_path' => $filePath,
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->media->markFailed($e->getMessage());
            throw $e;
        }
    }
}