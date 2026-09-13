<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\DTOs\VendorApplicationDTO;
use App\Models\Module;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use App\Services\VendorApplicationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;

class VendorApplicationParityTest extends HardeningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        // Drop minimal mock tables from parent and create realistic SQLite tables
        Schema::dropIfExists('stores');
        Schema::dropIfExists('vendors');

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->tinyInteger('status')->nullable();
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('logo')->default('def.png');
            $table->string('cover_photo')->default('def.png');
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->text('address')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('store_business_model')->default('commission');
            $table->string('minimum_delivery_time')->default('30');
            $table->string('maximum_delivery_time')->default('40');
            $table->string('delivery_time_type')->default('min');
            $table->string('tin')->nullable();
            $table->date('tin_expire_date')->nullable();
            $table->string('tin_certificate_image')->nullable();
            $table->string('delivery_time')->default('30-40 min');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->text('pickup_zone_id')->nullable();
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('coordinates')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->string('restaurant_wise_topic')->nullable();
            $table->string('customer_wise_topic')->nullable();
            $table->string('deliveryman_wise_topic')->nullable();
            $table->boolean('cash_on_delivery')->default(true);
            $table->boolean('digital_payment')->default(true);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_name');
            $table->string('module_type');
            $table->string('thumbnail')->nullable();
            $table->string('slug')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->integer('stores_count')->default(0);
            $table->boolean('all_zone_service')->default(false);
            $table->timestamps();
        });

        Schema::create('module_zone', function (Blueprint $table) {
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('zone_id');
            $table->double('per_km_shipping_charge')->nullable();
            $table->double('minimum_shipping_charge')->nullable();
            $table->double('maximum_shipping_charge')->nullable();
            $table->double('maximum_cod_order_amount')->nullable();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('store_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->integer('day');
            $table->time('opening_time');
            $table->time('closing_time');
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->string('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    public function test_web_and_whatsapp_dto_produce_identical_canonical_outcomes(): void
    {
        $zone = Zone::create([
            'name' => 'Ibadan Zone',
            'coordinates' => null,
            'status' => 1,
            'restaurant_wise_topic' => 'zone_1',
            'customer_wise_topic' => 'cust_1',
            'deliveryman_wise_topic' => 'dm_1',
            'cash_on_delivery' => true,
            'digital_payment' => true,
        ]);

        $module = Module::create([
            'module_name' => 'Grocery Ibadan',
            'module_type' => 'grocery',
            'thumbnail' => 'grocery.png',
            'status' => 1,
            'stores_count' => 0,
            'all_zone_service' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('module_zone')->insert([
            'module_id' => $module->id,
            'zone_id' => $zone->id,
        ]);

        $service = app(VendorApplicationService::class);

        // 1. Web submission
        $webRequest = new Request([
            'f_name' => 'Adebayo',
            'l_name' => 'Ogunlesi',
            'phone' => '+2348011112222',
            'email' => 'adebayo@example.com',
            'name' => ['default' => 'Adebayo Groceries'],
            'address' => ['default' => 'Ring Road, Ibadan'],
            'latitude' => 7.3775,
            'longitude' => 3.9470,
            'zone_id' => $zone->id,
            'module_id' => $module->id,
            'password' => 'SecurePass123!',
            'business_plan' => 'commission-base',
            'minimum_delivery_time' => '20',
            'maximum_delivery_time' => '40',
            'delivery_time_type' => 'min',
        ]);

        $webDto = VendorApplicationDTO::fromWebRequest($webRequest);
        $webResult = $service->submit($webDto);

        $this->assertNotNull($webResult['vendor']);
        $this->assertNotNull($webResult['store']);
        $this->assertEquals('Adebayo', $webResult['vendor']->f_name);
        $this->assertEquals('adebayo@example.com', $webResult['vendor']->email);
        $this->assertNull($webResult['vendor']->status); // Under review
        $this->assertEquals(0, $webResult['store']->status); // Pending approval
        $this->assertEquals('commission', $webResult['store']->store_business_model);
        $this->assertEquals('Adebayo Groceries', $webResult['store']->name);

        // 2. WhatsApp submission with equivalent data
        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348033334444',
            'phone_number' => '+2348033334444',
            'display_name' => 'Kudirat Supermarket',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'review',
            'current_step' => 'review_submit',
            'collected_data' => [
                'f_name' => 'Kudirat',
                'l_name' => 'Abiola',
                'phone' => '+2348033334444',
                'email' => 'kudirat@example.com',
                'business_name' => 'Kudirat Supermarket',
                'address' => 'Bodija Market, Ibadan',
                'latitude' => 7.4215,
                'longitude' => 3.9059,
                'zone_id' => $zone->id,
                'module_id' => $module->id,
                'password_hash' => bcrypt('SecurePass123!'),
                'business_plan' => 'commission-base',
                'delivery_time' => '20-40 min',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ],
        ]);

        $waDto = VendorApplicationDTO::fromWhatsAppSession($session, $contact);
        $waResult = $service->submit($waDto);

        $this->assertNotNull($waResult['vendor']);
        $this->assertNotNull($waResult['store']);
        $this->assertEquals('Kudirat', $waResult['vendor']->f_name);
        $this->assertEquals('kudirat@example.com', $waResult['vendor']->email);
        $this->assertNull($waResult['vendor']->status); // Under review
        $this->assertEquals(0, $waResult['store']->status); // Pending approval
        $this->assertEquals('commission', $waResult['store']->store_business_model);
        $this->assertEquals('Kudirat Supermarket', $waResult['store']->name);
    }

    public function test_duplicate_phone_or_email_fails_cleanly(): void
    {
        $zone = Zone::create([
            'name' => 'Ibadan Zone 2',
            'coordinates' => null,
            'status' => 1,
            'restaurant_wise_topic' => 'zone_2',
            'customer_wise_topic' => 'cust_2',
            'deliveryman_wise_topic' => 'dm_2',
            'cash_on_delivery' => true,
            'digital_payment' => true,
        ]);

        $module = Module::create([
            'module_name' => 'Pharmacy Ibadan',
            'module_type' => 'pharmacy',
            'thumbnail' => 'pharmacy.png',
            'status' => 1,
            'stores_count' => 0,
            'all_zone_service' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('module_zone')->insert([
            'module_id' => $module->id,
            'zone_id' => $zone->id,
        ]);

        $service = app(VendorApplicationService::class);

        // Pre-create an approved vendor and store
        $vendor = Vendor::create([
            'f_name' => 'Existing',
            'l_name' => 'Vendor',
            'phone' => '2348099998888',
            'email' => 'existing@example.com',
            'password' => bcrypt('password'),
            'status' => 1,
        ]);
        Store::create([
            'name' => 'Existing Pharmacy',
            'phone' => '2348099998888',
            'email' => 'existing@example.com',
            'address' => 'Ibadan Central',
            'latitude' => 7.3775,
            'longitude' => 3.9470,
            'vendor_id' => $vendor->id,
            'zone_id' => $zone->id,
            'module_id' => $module->id,
            'status' => 1,
            'store_business_model' => 'commission',
        ]);

        $contact = WhatsAppContact::create([
            'whatsapp_id' => '2348099998888',
            'phone_number' => '2348099998888',
            'display_name' => 'Duplicate Attempt',
        ]);

        $session = OnboardingSession::create([
            'contact_id' => $contact->id,
            'status' => 'review',
            'current_step' => 'review_submit',
            'collected_data' => [
                'f_name' => 'New',
                'l_name' => 'Owner',
                'phone' => '2348099998888',
                'email' => 'different@example.com',
                'business_name' => 'Duplicate Attempt',
                'address' => 'Ibadan',
                'latitude' => 7.3775,
                'longitude' => 3.9470,
                'zone_id' => $zone->id,
                'module_id' => $module->id,
                'password_hash' => bcrypt('SecurePass123!'),
                'business_plan' => 'commission-base',
                'terms_accepted' => true,
                'privacy_accepted' => true,
            ],
        ]);

        $waDto = VendorApplicationDTO::fromWhatsAppSession($session, $contact);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->submit($waDto);
    }
}
