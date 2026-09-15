<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use Modules\WhatsAppVendorConcierge\app\DTOs\VendorApplicationDTO;
use App\Models\Module;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\VendorApplicationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;

abstract class ApplicationFixtureTestCase extends HardeningTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // SQLite cannot execute the spatial predicate. Spatial behavior has its
        // own integration gate; this fixture covers mapping/persistence only.
        $this->app->instance(\Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ZoneEligibility::class,
            \Mockery::mock(\Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ZoneEligibility::class)
                ->shouldReceive('contains')->andReturn(true)->getMock());
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
            $table->text('rejection_note')->nullable();
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

        Schema::create('categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->nullable();
            $table->string('image')->nullable(); $table->integer('parent_id')->default(0);
            $table->integer('position')->default(0); $table->integer('priority')->default(0);
            $table->integer('module_id')->nullable(); $table->boolean('status')->default(true); $table->timestamps();
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

}
