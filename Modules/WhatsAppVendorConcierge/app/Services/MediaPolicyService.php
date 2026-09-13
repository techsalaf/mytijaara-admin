<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\Log;

interface MediaScannerInterface
{
    /**
     * @return array{clean: bool, threat: ?string}
     */
    public function scan(string $bytes, string $filename = ''): array;
}

class NullMediaScanner implements MediaScannerInterface
{
    public function scan(string $bytes, string $filename = ''): array
    {
        // Default clean scanner; swappable with ClamAV or external service
        return ['clean' => true, 'threat' => null];
    }
}

class MediaPolicyService
{
    public const PURPOSE_LOGO = 'logo';
    public const PURPOSE_COVER = 'cover';
    public const PURPOSE_PRODUCT = 'product';
    public const PURPOSE_KYC = 'kyc';

    protected MediaScannerInterface $scanner;

    public function __construct(?MediaScannerInterface $scanner = null)
    {
        $this->scanner = $scanner ?? new NullMediaScanner();
    }

    /**
     * Validate media bytes against purpose-specific policy.
     *
     * @return array{valid: bool, mime_type: string, file_size: int, width: ?int, height: ?int, error: ?string}
     */
    public function validate(string $purpose, string $bytes, string $extension = ''): array
    {
        $fileSize = strlen($bytes);
        if ($fileSize === 0) {
            return [
                'valid' => false,
                'mime_type' => 'unknown',
                'file_size' => 0,
                'width' => null,
                'height' => null,
                'error' => 'File is empty.',
            ];
        }

        // 1. Detect server-side MIME from bytes
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($bytes) ?: 'application/octet-stream';

        // 2. Purpose-specific limits & mime rules
        $limits = $this->getPurposeLimits($purpose);
        if ($fileSize > $limits['max_size']) {
            return [
                'valid' => false,
                'mime_type' => $detectedMime,
                'file_size' => $fileSize,
                'width' => null,
                'height' => null,
                'error' => "File size exceeds the {$limits['max_size_mb']}MB limit for {$purpose}.",
            ];
        }

        if (!in_array($detectedMime, $limits['allowed_mimes'], true)) {
            return [
                'valid' => false,
                'mime_type' => $detectedMime,
                'file_size' => $fileSize,
                'width' => null,
                'height' => null,
                'error' => "File format '{$detectedMime}' is not permitted for {$purpose}.",
            ];
        }

        // 3. Extension / MIME consistency check if extension provided
        if (!empty($extension)) {
            $normalizedExt = strtolower(ltrim($extension, '.'));
            $expectedExts = $this->mimeToExtensions($detectedMime);
            if (!empty($expectedExts) && !in_array($normalizedExt, $expectedExts, true)) {
                return [
                    'valid' => false,
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'width' => null,
                    'height' => null,
                    'error' => "File extension '.{$normalizedExt}' does not match detected content type '{$detectedMime}'.",
                ];
            }
        }

        // 4. Image decoding, resolution & aspect ratio checks
        $width = null;
        $height = null;

        if (str_starts_with($detectedMime, 'image/')) {
            $imageInfo = @getimagesizefromstring($bytes);
            if ($imageInfo === false) {
                return [
                    'valid' => false,
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'width' => null,
                    'height' => null,
                    'error' => 'Image corrupted or cannot be decoded.',
                ];
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Decompression bomb protection: max 25 megapixels
            if (($width * $height) > 25000000) {
                return [
                    'valid' => false,
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'width' => $width,
                    'height' => $height,
                    'error' => 'Image resolution is too large (exceeds 25 megapixels).',
                ];
            }

            // Aspect ratio checks
            if ($purpose === self::PURPOSE_LOGO) {
                $ratio = $width / max(1, $height);
                // 1:1 square ratio with tolerance (0.8 to 1.25)
                if ($ratio < 0.75 || $ratio > 1.35) {
                    return [
                        'valid' => false,
                        'mime_type' => $detectedMime,
                        'file_size' => $fileSize,
                        'width' => $width,
                        'height' => $height,
                        'error' => 'Store logo must be approximately square (1:1 ratio).',
                    ];
                }
            } elseif ($purpose === self::PURPOSE_COVER) {
                $ratio = $width / max(1, $height);
                // 2:1 landscape ratio (1.4 to 2.6)
                if ($ratio < 1.3 || $ratio > 2.8) {
                    return [
                        'valid' => false,
                        'mime_type' => $detectedMime,
                        'file_size' => $fileSize,
                        'width' => $width,
                        'height' => $height,
                        'error' => 'Cover photo must be landscape oriented (approximately 2:1 ratio).',
                    ];
                }
            }
        } elseif ($detectedMime === 'application/pdf') {
            if (!str_starts_with($bytes, '%PDF-')) {
                return [
                    'valid' => false,
                    'mime_type' => $detectedMime,
                    'file_size' => $fileSize,
                    'width' => null,
                    'height' => null,
                    'error' => 'PDF document header is malformed.',
                ];
            }
        }

        // 5. Malware scanning
        $scan = $this->scanner->scan($bytes);
        if (!$scan['clean']) {
            Log::warning('Malware detected in uploaded media', ['purpose' => $purpose, 'threat' => $scan['threat']]);
            return [
                'valid' => false,
                'mime_type' => $detectedMime,
                'file_size' => $fileSize,
                'width' => $width,
                'height' => $height,
                'error' => 'File rejected by security scanner.',
            ];
        }

        return [
            'valid' => true,
            'mime_type' => $detectedMime,
            'file_size' => $fileSize,
            'width' => $width,
            'height' => $height,
            'error' => null,
        ];
    }

    /**
     * Strip EXIF and metadata by re-encoding image.
     */
    public function stripExif(string $bytes, string $mimeType): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return $bytes;
        }

        ob_start();
        try {
            switch ($mimeType) {
                case 'image/png':
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagepng($image);
                    break;
                case 'image/webp':
                    imagepalettetotruecolor($image);
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                    imagewebp($image, null, 90);
                    break;
                case 'image/jpeg':
                default:
                    imagejpeg($image, null, 90);
                    break;
            }
            $cleanBytes = ob_get_clean();
            imagedestroy($image);
            return $cleanBytes ?: $bytes;
        } catch (\Throwable $e) {
            ob_end_clean();
            imagedestroy($image);
            return $bytes;
        }
    }

    /**
     * Generate safe, non-predictable filename.
     */
    public function generateSafeFilename(string $extension, string $prefix = ''): string
    {
        $ext = strtolower(ltrim($extension, '.'));
        $prefix = $prefix ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $prefix) . '-' : '';
        return \Carbon\Carbon::now()->toDateString() . '-' . $prefix . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /**
     * Validate product image media against canonical product policy.
     */
    public function validateProductImage(mixed $media): array
    {
        if ($media instanceof \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia) {
            $disk = $media->storage_disk ?: 'public';
            if (!\Illuminate\Support\Facades\Storage::disk($disk)->exists($media->file_path)) {
                return ['valid' => false, 'error' => 'Media file not found.'];
            }
            $bytes = \Illuminate\Support\Facades\Storage::disk($disk)->get($media->file_path);
            return $this->validate(self::PURPOSE_PRODUCT, $bytes, pathinfo($media->file_path, PATHINFO_EXTENSION));
        }

        if (is_string($media) && @file_exists($media)) {
            $bytes = @file_get_contents($media) ?: '';
            return $this->validate(self::PURPOSE_PRODUCT, $bytes, pathinfo($media, PATHINFO_EXTENSION));
        }

        if (is_string($media)) {
            return $this->validate(self::PURPOSE_PRODUCT, $media);
        }

        return ['valid' => false, 'error' => 'Invalid media input.'];
    }

    /**
     * Get limits configuration by purpose.
     */
    protected function getPurposeLimits(string $purpose): array
    {
        return match ($purpose) {
            self::PURPOSE_LOGO => [
                'max_size' => 2048 * 1024, // 2MB
                'max_size_mb' => 2,
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            ],
            self::PURPOSE_COVER => [
                'max_size' => 2048 * 1024, // 2MB
                'max_size_mb' => 2,
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            ],
            self::PURPOSE_PRODUCT => [
                'max_size' => 2048 * 1024, // 2MB
                'max_size_mb' => 2,
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            ],
            self::PURPOSE_KYC => [
                'max_size' => 5120 * 1024, // 5MB
                'max_size_mb' => 5,
                'allowed_mimes' => [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                    'application/pdf',
                ],
            ],
            default => [
                'max_size' => 10240 * 1024,
                'max_size_mb' => 10,
                'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            ],
        };
    }

    protected function mimeToExtensions(string $mime): array
    {
        return match ($mime) {
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            default => [],
        };
    }
}
