<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Admin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Services\MediaPolicyService;
use PHPUnit\Framework\Attributes\Test;

class MediaSecurityPolicyTest extends HardeningTestCase
{
    #[Test]
    public function valid_logo_passes_policy_while_distorted_ratio_fails(): void
    {
        $policy = app(MediaPolicyService::class);

        // 100x100 square PNG
        $squareImg = imagecreatetruecolor(100, 100);
        ob_start();
        imagepng($squareImg);
        $squareBytes = ob_get_clean();
        imagedestroy($squareImg);

        $result = $policy->validate(MediaPolicyService::PURPOSE_LOGO, $squareBytes, 'png');
        $this->assertTrue($result['valid']);
        $this->assertSame('image/png', $result['mime_type']);

        // 100x300 distorted image (ratio 0.33)
        $distortedImg = imagecreatetruecolor(100, 300);
        ob_start();
        imagepng($distortedImg);
        $distortedBytes = ob_get_clean();
        imagedestroy($distortedImg);

        $failResult = $policy->validate(MediaPolicyService::PURPOSE_LOGO, $distortedBytes, 'png');
        $this->assertFalse($failResult['valid']);
        $this->assertStringContainsString('square', $failResult['error']);
    }

    #[Test]
    public function cover_photo_requires_landscape_aspect_ratio(): void
    {
        $policy = app(MediaPolicyService::class);

        // 200x100 landscape image (2:1 ratio)
        $coverImg = imagecreatetruecolor(200, 100);
        ob_start();
        imagepng($coverImg);
        $coverBytes = ob_get_clean();
        imagedestroy($coverImg);

        $result = $policy->validate(MediaPolicyService::PURPOSE_COVER, $coverBytes, 'png');
        $this->assertTrue($result['valid']);

        // 100x200 vertical image (0.5:1 ratio)
        $verticalImg = imagecreatetruecolor(100, 200);
        ob_start();
        imagepng($verticalImg);
        $verticalBytes = ob_get_clean();
        imagedestroy($verticalImg);

        $failResult = $policy->validate(MediaPolicyService::PURPOSE_COVER, $verticalBytes, 'png');
        $this->assertFalse($failResult['valid']);
        $this->assertStringContainsString('landscape', $failResult['error']);
    }

    #[Test]
    public function mismatched_extension_and_mime_type_is_rejected(): void
    {
        $policy = app(MediaPolicyService::class);

        // PNG bytes with .pdf extension
        $img = imagecreatetruecolor(50, 50);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        $result = $policy->validate(MediaPolicyService::PURPOSE_LOGO, $bytes, 'pdf');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('does not match', $result['error']);
    }

    #[Test]
    public function kyc_access_requires_admin_authorization(): void
    {
        Storage::fake('local');
        $filePath = 'whatsapp/media/test-kyc.pdf';
        Storage::disk('local')->put($filePath, '%PDF-1.4 test content');

        $media = WhatsAppMedia::create([
            'whatsapp_media_id' => 'kyc-media-auth',
            'file_path' => $filePath,
            'storage_disk' => 'local',
            'status' => 'processed',
            'mime_type' => 'application/pdf',
        ]);

        // Anonymous access redirected or rejected by admin middleware
        $this->get('/admin/whatsapp/kyc/' . $media->id)->assertRedirect();

        // Authenticated admin permitted
        $admin = new Admin(['role_id' => 1, 'is_logged_in' => true, 'login_remember_token' => 'test-session']);
        $admin->id = 99;
        $admin->setRelation('role', new \App\Models\AdminRole(['modules' => json_encode(['store'])]));

        $this->actingAs($admin, 'admin')
            ->withSession(['login_remember_token' => 'test-session'])
            ->get('/admin/whatsapp/kyc/' . $media->id)
            ->assertOk();
    }
}
