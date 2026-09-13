<?php

namespace Modules\WhatsAppVendorConcierge\tests\Hardening;

use App\Models\Item;
use App\Models\Module;
use App\Models\Order;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Services\VendorAnalyticsService;
use Modules\WhatsAppVendorConcierge\app\Services\VendorReadinessService;
use Modules\WhatsAppVendorConcierge\app\Services\VendorSubscriptionReadService;
use Modules\WhatsAppVendorConcierge\app\Services\VendorWalletReadService;

class VendorCommandCentreTest extends HardeningTestCase
{
    protected Vendor $vendor;
    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::dropIfExists('stores');
        Schema::dropIfExists('vendors');

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->unique();
            $table->string('bank_name')->nullable();
            $table->string('account_no')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('store_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->double('total_earning', 24, 2)->default(0);
            $table->double('pending_withdraw', 24, 2)->default(0);
            $table->double('total_withdrawn', 24, 2)->default(0);
            $table->double('collected_cash', 24, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('store_schedule', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->integer('day');
            $table->time('opening_time');
            $table->time('closing_time');
            $table->timestamps();
        });

        Schema::create('store_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->double('price', 24, 2)->default(0);
            $table->date('expiry_date')->nullable();
            $table->boolean('is_active')->default(1);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('subscription_packages', function (Blueprint $table) {
            $table->id();
            $table->string('package_name');
            $table->double('price', 24, 2)->default(0);
            $table->integer('max_order')->default(0);
            $table->integer('max_item')->default(0);
            $table->timestamps();
        });

        Schema::create('order_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });

        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->double('amount', 24, 2);
            $table->string('status')->default('pending');
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
            $table->tinyInteger('status')->default(1);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('store_business_model')->default('commission');
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->double('price', 24, 2)->default(0);
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('order_count')->default(0);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->double('order_amount', 24, 2)->default(0);
            $table->string('order_status')->default('pending');
            $table->unsignedBigInteger('store_id');
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale')->default('en');
            $table->string('key');
            $table->text('value')->nullable();
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

        $this->vendor = Vendor::create([
            'f_name' => 'Bolaji',
            'l_name' => 'Tinubu',
            'phone' => '+2348055551122',
            'email' => 'bolaji@example.com',
            'bank_name' => 'First Bank of Nigeria',
            'account_no' => '0123456789',
            'status' => 1,
        ]);

        $this->store = new Store();
        $this->store->name = 'Bolaji Fresh Mart';
        $this->store->phone = '+2348055551122';
        $this->store->address = 'Iwo Road, Ibadan';
        $this->store->latitude = '7.4019';
        $this->store->longitude = '3.9173';
        $this->store->vendor_id = $this->vendor->id;
        $this->store->zone_id = 1;
        $this->store->module_id = 1;
        $this->store->status = 1;
        $this->store->active = true;
        $this->store->store_business_model = 'commission';
        $this->store->save();
    }

    public function test_vendor_readiness_score_calculation(): void
    {
        $service = app(VendorReadinessService::class);

        // Initially without items or schedules
        $readiness = $service->calculateReadiness($this->store);

        $this->assertEquals(55, $readiness['score']);
        $this->assertContains('First Product Added', $readiness['blocking_items']);
        $this->assertStringContainsString('Shop Launch Readiness', $readiness['formatted_message']);

        // Add schedules, branding and products
        $this->store->update(['logo' => 'logo.png', 'cover_photo' => 'cover.png']);
        \Illuminate\Support\Facades\DB::table('store_schedule')->insert([
            'store_id' => $this->store->id,
            'day' => 1,
            'opening_time' => '08:00:00',
            'closing_time' => '20:00:00',
        ]);

        Item::create([
            'name' => 'Fresh Plantains',
            'price' => 2000.00,
            'store_id' => $this->store->id,
            'stock' => 15,
            'status' => 1,
        ]);

        $fullReadiness = $service->calculateReadiness($this->store);
        $this->assertEquals(100, $fullReadiness['score']);
        $this->assertEmpty($fullReadiness['blocking_items']);
        $this->assertStringContainsString('100%', $fullReadiness['formatted_message']);
    }

    public function test_vendor_wallet_read_masks_pii(): void
    {
        \Illuminate\Support\Facades\DB::table('store_wallets')->insert([
            'vendor_id' => $this->vendor->id,
            'total_earning' => 142000.50,
            'pending_withdraw' => 12000.00,
            'total_withdrawn' => 85000.00,
            'collected_cash' => 0.0,
        ]);

        \Illuminate\Support\Facades\DB::table('withdraw_requests')->insert([
            'vendor_id' => $this->vendor->id,
            'amount' => 12000.00,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $service = app(VendorWalletReadService::class);
        $walletSummary = $service->getWalletSummary($this->vendor);

        $this->assertEquals(45000.50, $walletSummary['balance']);
        $this->assertEquals(12000.00, $walletSummary['pending_withdraw']);
        $this->assertEquals(85000.00, $walletSummary['total_withdrawn']);
        $this->assertTrue($walletSummary['payout_configured']);

        // Assert Account number is masked (e.g. ******6789)
        $this->assertEquals('******6789', $walletSummary['account_masked']);
        $this->assertStringNotContainsString('0123456789', $walletSummary['formatted_message']);
        $this->assertStringContainsString('******6789', $walletSummary['formatted_message']);
    }

    public function test_vendor_subscription_read_service(): void
    {
        $service = app(VendorSubscriptionReadService::class);

        // Commission store
        $summary = $service->getSubscriptionSummary($this->store);

        $this->assertEquals('commission', $summary['business_model']);
        $this->assertFalse($summary['is_subscription']);
        $this->assertStringContainsString('Pay-as-you-go Commission', $summary['formatted_message']);
    }

    public function test_vendor_analytics_service_canonical_accounting(): void
    {
        // 1 Delivered order (revenue)
        $o1 = new Order();
        $o1->order_amount = 7500.00;
        $o1->order_status = 'delivered';
        $o1->store_id = $this->store->id;
        $o1->created_at = now();
        $o1->save();

        // 1 Pending order (not revenue)
        $o2 = new Order();
        $o2->order_amount = 3000.00;
        $o2->order_status = 'pending';
        $o2->store_id = $this->store->id;
        $o2->created_at = now();
        $o2->save();

        // 1 Canceled order (not revenue)
        $o3 = new Order();
        $o3->order_amount = 4500.00;
        $o3->order_status = 'canceled';
        $o3->store_id = $this->store->id;
        $o3->created_at = now();
        $o3->save();

        // Low stock item
        Item::create([
            'name' => 'Low Stock Beans',
            'price' => 1800.00,
            'store_id' => $this->store->id,
            'stock' => 3,
            'order_count' => 12,
            'status' => 1,
        ]);

        $service = app(VendorAnalyticsService::class);
        $insights = $service->getBusinessInsights($this->store);

        // Canonical rule: only delivered order (7500) counts in sales
        $this->assertEquals(7500.00, $insights['sales_summary']['today']);
        $this->assertEquals(1, $insights['order_summary']['delivered']);
        $this->assertEquals(1, $insights['order_summary']['pending']);
        $this->assertEquals(1, $insights['order_summary']['canceled']);

        // Low stock alerts
        $this->assertCount(1, $insights['low_stock_items']);
        $this->assertEquals('Low Stock Beans', $insights['low_stock_items'][0]['name']);
        $this->assertStringContainsString('Low Stock Alert', $insights['formatted_message']);
    }
}
