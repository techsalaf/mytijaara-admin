<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** Source parity is a guard against drift, not a replacement for MySQL HTTP tests. */
final class RegistrationSourceContractTest extends TestCase
{
    public function test_public_registration_matches_the_reviewed_pre_extraction_workflow(): void
    {
        $this->assertMethod('app/Http/Controllers/VendorController.php', 'store', 'Controllers-VendorController-store');
    }

    public function test_store_app_availability_contract_is_unchanged(): void
    {
        $this->assertMethod('app/Http/Controllers/Api/V1/Vendor/VendorController.php', 'active_status', 'Vendor-VendorController-active_status');
    }

    public function test_vendor_panel_availability_contract_is_unchanged(): void
    {
        $this->assertMethod('app/Http/Controllers/Vendor/BusinessSettingsController.php', 'active_status', 'Vendor-BusinessSettingsController-active_status');
    }

    private function assertMethod(string $path, string $method, string $fixture): void
    {
        $source = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../../'.$path));
        preg_match('/    public function '.preg_quote($method, '/').'\(.*?(?=\n    (?:public|private|protected) function |\z)/s', $source, $match);
        $expected = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/fixtures/'.$fixture.'.txt'));
        $this->assertSame($expected, $match[0] ?? 'method missing');
    }
}
