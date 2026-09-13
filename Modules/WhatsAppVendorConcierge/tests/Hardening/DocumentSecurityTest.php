<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Jobs\ProcessVendorDocument;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use PHPUnit\Framework\Attributes\Test;

class DocumentSecurityTest extends HardeningTestCase
{
    #[Test]
    public function missing_or_pending_media_can_never_be_marked_processed(): void
    {
        Storage::fake('local');
        $pending = WhatsAppMedia::create(['whatsapp_media_id' => 'pending-media', 'mime_type' => 'image/png']);
        (new ProcessVendorDocument($pending))->handle();
        $this->assertSame('pending_download', $pending->fresh()->status);

        $missing = WhatsAppMedia::create([
            'whatsapp_media_id' => 'missing-media', 'mime_type' => 'image/png', 'file_path' => 'whatsapp/media/missing.png',
            'storage_disk' => 'local', 'file_size' => 10, 'status' => 'downloaded',
        ]);
        (new ProcessVendorDocument($missing))->handle();
        $this->assertSame('failed', $missing->fresh()->status);
        $this->assertNull($missing->fresh()->ocr_result);
    }

    #[Test]
    public function real_decodable_bytes_are_only_structurally_validated_pending_human_review(): void
    {
        Storage::fake('local');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8QAAAAABJRU5ErkJggg==');
        Storage::disk('local')->put('whatsapp/media/logo.png', $bytes);
        $media = WhatsAppMedia::create([
            'whatsapp_media_id' => 'valid-media', 'mime_type' => 'image/png', 'file_path' => 'whatsapp/media/logo.png',
            'storage_disk' => 'local', 'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'downloaded',
        ]);

        (new ProcessVendorDocument($media))->handle();

        $result = $media->fresh();
        $this->assertSame('processed', $result->status);
        $this->assertSame('structurally_validated', $result->ocr_result['validation_state']);
        $this->assertSame('awaiting_human_review', $result->ocr_result['kyc_review_status']);
        $this->assertFalse($result->ocr_result['fields']['human_kyc_approved']);
        $this->assertEquals(0.0, $result->ocr_result['confidence']);
    }
}
