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

class ProcessVendorDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(
        public WhatsAppMedia $media
    ) {}

    public function handle(): void
    {
        try {
            $this->media->refresh();

            if ($this->media->status !== 'downloaded') {
                return;
            }

            // Update status to processing
            $this->media->update(['status' => 'processing']);

            $diskName = $this->media->storage_disk;
            $filePath = $this->media->file_path;
            if (!filled($diskName) || !filled($filePath)) {
                $this->fail('Missing downloaded file reference');
                return;
            }

            $disk = Storage::disk($diskName);
            if (!$disk->exists($filePath)) {
                $this->fail('Downloaded file is unavailable');
                return;
            }

            $bytes = $disk->get($filePath);
            if ($bytes === false || $bytes === '') {
                $this->fail('Downloaded file is empty or unreadable', true);
                return;
            }

            $fileSize = strlen($bytes);
            if ($this->media->file_size !== null && (int) $this->media->file_size !== $fileSize) {
                $this->fail('Downloaded file size does not match its recorded size', true);
                return;
            }

            $checksum = hash('sha256', $bytes);
            if (filled($this->media->sha256) && !hash_equals(strtolower($this->media->sha256), $checksum)) {
                $this->fail('Downloaded file checksum does not match its recorded checksum', true);
                return;
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
            if ($this->media->mime_type !== 'unknown' && $this->media->mime_type !== $mimeType) {
                $this->fail('Downloaded file type does not match its recorded type', true);
                return;
            }

            $image = null;
            if (str_starts_with($mimeType, 'image/')) {
                $image = @getimagesizefromstring($bytes);
                if ($image === false) {
                    $this->fail('Image bytes cannot be decoded', true);
                    return;
                }
            } elseif ($mimeType === 'application/pdf' && !str_starts_with($bytes, '%PDF-')) {
                $this->fail('PDF bytes are malformed', true);
                return;
            } elseif (!str_starts_with($mimeType, 'image/') && $mimeType !== 'application/pdf') {
                $this->fail('Document type is not supported', true);
                return;
            }

            // Classify document type based on mime/extension
            $docType = match (true) {
                str_contains($mimeType, 'pdf') => 'business_registration_pdf',
                str_contains($mimeType, 'image') => 'identity_card_or_logo',
                default => 'vendor_supporting_document',
            };

            $ocrResult = [
                'document_type' => $docType,
                'mime_type' => $mimeType,
                'file_size_bytes' => $fileSize,
                'checksum_sha256' => $checksum,
                'validation_state' => 'structurally_validated',
                'kyc_review_status' => 'awaiting_human_review',
                'extracted_text' => null,
                // Structural validation is not a KYC decision or an OCR result.
                'confidence' => 0.0,
                'fields' => [
                    'format_structurally_validated' => true,
                    'human_kyc_approved' => false,
                    'file_name' => basename((string) $filePath),
                    'image_width' => $image[0] ?? null,
                    'image_height' => $image[1] ?? null,
                ],
                'processed_at' => now()->toISOString(),
            ];

            $this->media->markProcessed($ocrResult);

            Log::info('Vendor document processed with structural verification', [
                'media_id' => $this->media->id,
                'document_type' => $docType,
                'size_bytes' => $fileSize,
            ]);

        } catch (\Throwable $e) {
            Log::error('ProcessVendorDocument failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);
            $this->media->markFailed($e->getMessage());
            throw $e;
        }
    }

    private function fail(string $reason, bool $deleteFile = false): void
    {
        if ($deleteFile && $this->media->file_path && $this->media->storage_disk) {
            Storage::disk($this->media->storage_disk)->delete($this->media->file_path);
        }
        $this->media->markFailed($reason);
    }
}
