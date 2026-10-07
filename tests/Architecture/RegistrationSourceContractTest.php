<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** Source parity is a guard against drift, not a replacement for MySQL HTTP tests. */
final class RegistrationSourceContractTest extends TestCase
{
    public function test_public_registration_matches_the_reviewed_shared_service_delegation(): void
    {
        $this->assertMethod('app/Http/Controllers/VendorController.php', 'store', 'Controllers-VendorController-store');
    }

    public function test_registration_writes_have_one_channel_neutral_owner(): void
    {
        $root = __DIR__.'/../../';
        $controller = file_get_contents($root.'app/Http/Controllers/VendorController.php');
        $adapter = file_get_contents($root.'Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/VendorApplicationService.php');
        $service = file_get_contents($root.'app/Services/VendorSelfRegistrationService.php');
        preg_match('/    public function store\(.*?(?=\n    public function get_all_modules)/s', $controller, $registration);
        $this->assertStringContainsString('VendorSelfRegistrationService::class', $controller);
        $this->assertStringContainsString('$this->registration->register(', $adapter);
        foreach ([$registration[0], $adapter] as $caller) {
            $this->assertDoesNotMatchRegularExpression('/new\s+(?:Vendor|Store)\b|\$(?:vendor|store)->(?:save|update)\(/', $caller);
        }
        $this->assertStringNotContainsString('WhatsAppVendorConcierge', $service);
        $this->assertStringNotContainsString('Illuminate\\Http\\Request', $service);
        $this->assertStringNotContainsString('Mail::', $service);
        $this->assertStringContainsString('DB::afterCommit(', $service);
        $manifest = json_decode(file_get_contents($root.'scripts/core-patches.json'), true);
        $this->assertNotContains('Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/VendorApplicationService.php', $manifest['protected_write_owners']);
    }

    public function test_api_registration_delegates_without_inline_writes_or_optional_module_dependencies(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Http/Controllers/Api/V1/Auth/VendorLoginController.php');
        preg_match('/    public function register\(.*\z/s', $source, $method);
        $this->assertStringContainsString('VendorSelfRegistrationService::class', $method[0]);
        $this->assertDoesNotMatchRegularExpression('/new\s+(?:Vendor|Store)\b|\$(?:vendor|store)->(?:save|update)\(/', $method[0]);
        $this->assertStringNotContainsString('WhatsAppVendorConcierge', $method[0]);
        $this->assertStringNotContainsString('return back()', $method[0]);
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
