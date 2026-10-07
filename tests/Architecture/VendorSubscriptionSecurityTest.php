<?php

namespace Tests\Architecture;

use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class VendorSubscriptionSecurityTest extends HostWithoutConciergeTest
{
    private ?FullCoreDatabase $fixture = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') {
            $this->markTestSkipped('Requires loopback-only disposable full-schema fixture.');
        }
        parent::setUp();
        if (! in_array($this->name(), [
            'test_payment_with_forged_renew_type_does_not_approve_pending_vendor',
            'test_payment_cannot_reactivate_suspended_store',
            'test_approved_active_store_can_complete_paid_subscription',
        ], true)) {
            return;
        }
        $this->fixture = new FullCoreDatabase;
        $this->fixture->create();
        config(['mail.status' => false]);
        Mail::fake();
        Http::preventStrayRequests();
        DB::table('vendors')->insert(['id' => 1, 'f_name' => 'Fixture', 'phone' => '2348000000001', 'email' => 'fixture@example.test', 'status' => null, 'password' => bcrypt('Fixture!123')]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Groceries', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('stores')->insert(['id' => 1, 'vendor_id' => 1, 'module_id' => 1, 'name' => 'Fixture', 'phone' => '2348000000001',
            'status' => 0, 'store_business_model' => 'none', 'delivery_time' => '20-30']);
        DB::table('subscription_packages')->insert(['id' => 1, 'package_name' => 'Fixture', 'price' => 100, 'validity' => 30,
            'module_type' => 'all', 'mobile_app' => 1]);
    }

    protected function tearDown(): void
    {
        try {
            $this->fixture?->destroy();
        } finally {
            parent::tearDown();
        }
    }

    public function test_payment_with_forged_renew_type_does_not_approve_pending_vendor(): void
    {
        $id = Helpers::subscription_plan_chosen(1, 1, 'manual_payment_by_admin', type: 'renew');
        $this->assertNotFalse($id);
        $this->assertNull(DB::table('vendors')->value('status'));
        $this->assertSame(0, (int) DB::table('stores')->value('status'));
        $this->assertSame(0, (int) DB::table('store_subscriptions')->value('status'));
    }

    public function test_payment_cannot_reactivate_suspended_store(): void
    {
        DB::table('vendors')->update(['status' => 1]);
        $this->assertNotFalse(Helpers::subscription_plan_chosen(1, 1, 'manual_payment_by_admin', type: 'renew'));
        $this->assertSame(0, (int) DB::table('stores')->value('status'));
        $this->assertSame(0, (int) DB::table('store_subscriptions')->value('status'));
    }

    public function test_approved_active_store_can_complete_paid_subscription(): void
    {
        DB::table('vendors')->update(['status' => 1]);
        DB::table('stores')->update(['status' => 1]);
        $this->assertNotFalse(Helpers::subscription_plan_chosen(1, 1, 'manual_payment_by_admin', type: 'new_join'));
        $this->assertSame(1, (int) DB::table('stores')->value('status'));
        $this->assertSame(1, (int) DB::table('store_subscriptions')->value('status'));
    }
}
