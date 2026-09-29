<?php

namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\BusinessSetting;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Support\Facades\Mail;
use Modules\WhatsAppVendorConcierge\app\Mail\StoreProductUploadNudgeMail;
use Modules\WhatsAppVendorConcierge\app\Services\WhatsAppGateway;
use Modules\WhatsAppVendorConcierge\tests\Hardening\ApplicationFixtureTestCase;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class StoreOutreachTest extends ApplicationFixtureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('items');
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function test_active_store_outreach_dry_run_does_not_send_messages(): void
    {
        Mail::fake();
        $mockGateway = $this->createMock(WhatsAppGateway::class);
        $mockGateway->expects($this->never())->method('sendTemplateMessage');
        $this->app->instance(WhatsAppGateway::class, $mockGateway);

        // Create an active store
        $vendor = Vendor::create([
            'f_name' => 'Akin',
            'l_name' => 'Olawale',
            'phone' => '2349032617923',
            'email' => 'test@example.com',
            'status' => 1,
        ]);

        Store::create([
            'name' => 'Tijaara Test Store',
            'phone' => '2349032617923',
            'email' => 'test@example.com',
            'vendor_id' => $vendor->id,
            'status' => 1,
            'address' => 'Lagos',
            'latitude' => 6.5244,
            'longitude' => 3.3792,
        ]);

        $this->artisan('concierge:notify-active-stores --channel=all')
            ->expectsOutputToContain('DRY RUN PREVIEW')
            ->expectsOutputToContain('Tijaara Test Store')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_active_store_mailable_renders_expected_details(): void
    {
        $details = [
            'store_id' => 999,
            'store_name' => 'Test Mart',
            'vendor_name' => 'John Doe',
            'email' => 'john@example.com',
            'items_count' => 0,
            'login_url' => 'https://dashboard.mytijaara.com/vendor/auth/login',
        ];

        $mailable = new StoreProductUploadNudgeMail($details);
        $rendered = $mailable->render();

        $this->assertStringContainsString('Test Mart', $rendered);
        $this->assertStringContainsString('John Doe', $rendered);
        $this->assertStringContainsString('100% FREE Product Uploads', $rendered);
        $this->assertStringContainsString('https://dashboard.mytijaara.com/vendor/auth/login', $rendered);
    }
}
