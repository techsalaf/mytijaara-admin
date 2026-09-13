<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Illuminate\Support\Facades\Artisan;

class PreflightTest extends HardeningTestCase
{
    public function test_strict_preflight_passes_for_a_complete_test_configuration(): void
    {
        config([
            'whatsapp-vendor-concierge.api.business_account_id' => 'test-waba',
            'whatsapp-vendor-concierge.api.app_id' => 'test-app',
            'whatsapp-vendor-concierge.api.verify_token' => 'test-verify',
            'whatsapp-vendor-concierge.media.storage_disk' => 'local',
            'whatsapp-vendor-concierge.features.product_creation' => false,
            'whatsapp-vendor-concierge.features.order_management' => false,
            'filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app')],
        ]);

        $exitCode = Artisan::call('whatsapp:preflight', ['--strict' => true]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
