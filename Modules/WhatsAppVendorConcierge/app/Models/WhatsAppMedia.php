<?php

namespace Modules\WhatsAppVendorConcierge\app\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMedia extends Model
{
    protected $table = 'whatsapp_media';

    protected $fillable = [
        'whatsapp_media_id',
        'mime_type',
        'sha256',
        'file_size',
        'file_path',
        'storage_disk',
        'status',
        'metadata',
        'ocr_result',
        'downloaded_at',
        'expires_at',
        'cleanup_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'ocr_result' => 'array',
        'downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
        'cleanup_at' => 'datetime',
    ];

    /**
     * Find or create media record by WhatsApp media ID.
     */
    public static function findOrCreateByWhatsAppId(string $mediaId, array $metadata = []): self
    {
        return self::firstOrCreate(
            ['whatsapp_media_id' => $mediaId],
            [
                'mime_type' => $metadata['mime_type'] ?? 'unknown',
                'file_size' => $metadata['file_size'] ?? null,
                'metadata' => $metadata,
                'status' => 'pending_download',
                'expires_at' => now()->addDays(7), // Meta URLs expire after 7 days
            ]
        );
    }

    /**
     * Mark as downloaded.
     */
    public function markDownloaded(string $filePath, string $disk): void
    {
        $this->update([
            'file_path' => $filePath,
            'storage_disk' => $disk,
            'status' => 'downloaded',
            'downloaded_at' => now(),
            'cleanup_at' => now()->addDays(config('whatsapp-vendor-concierge.media.cleanup_after_days', 30)),
        ]);
    }

    /**
     * Mark as processed.
     */
    public function markProcessed(array $ocrResult = []): void
    {
        $this->update([
            'status' => 'processed',
            'ocr_result' => $ocrResult,
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'metadata' => array_merge($this->metadata ?? [], ['error' => $error]),
        ]);
    }

    /**
     * Get full file URL.
     */
    public function getUrl(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk($this->storage_disk)->url($this->file_path);
    }

    /**
     * Get file contents.
     */
    public function getContents(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk($this->storage_disk)->get($this->file_path);
    }
}