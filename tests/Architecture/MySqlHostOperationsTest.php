<?php
namespace Tests\Architecture;

use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MySqlHostOperationsTest extends HostWithoutConciergeTest
{
    private ?FullCoreDatabase $fixture = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') $this->markTestSkipped('Requires disposable loopback MySQL.');
        parent::setUp();
        if ($this->name() !== 'test_product_stock_status_and_order_transitions_without_optional_classes') return;
        $this->fixture = new FullCoreDatabase();
        $this->fixture->create();
        config(['mail.status' => false, 'order_confirmation_model' => 'store']);
        Mail::fake(); Http::preventStrayRequests();
        DB::table('vendors')->insert(['id' => 1, 'f_name' => 'Vendor', 'phone' => '2348000000001',
            'email' => 'vendor@example.test', 'status' => 1, 'password' => bcrypt('Fixture-Only!123')]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('stores')->insert(['id' => 1, 'vendor_id' => 1, 'module_id' => 1, 'name' => 'Fixture',
            'phone' => '2348000000001',
            'status' => 1, 'active' => 1, 'item_section' => 1, 'store_business_model' => 'commission', 'delivery_time' => '20-30']);
        $this->actingAs(Vendor::findOrFail(1), 'vendor');
    }

    protected function tearDown(): void
    {
        try { $this->fixture?->destroy(); } finally { parent::tearDown(); }
    }

    public function test_product_stock_status_and_order_transitions_without_optional_classes(): void
    {
        DB::table('items')->insert(['id' => 1, 'store_id' => 1, 'module_id' => 1, 'name' => 'Rice',
            'price' => 100, 'stock' => 2, 'status' => 1]);
        $products = app(\App\Http\Controllers\Vendor\ItemController::class);
        $response = $products->stock_update(new Request(['product_id' => 1, 'current_stock' => 7,
            'type' => ['Small'], 'price_0_Small' => 100, 'stock_0_Small' => 7]));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertEquals(7, DB::table('items')->where('id', 1)->value('stock'));
        $this->assertSame(7, json_decode(DB::table('items')->where('id', 1)->value('variations'), true)[0]['stock']);
        foreach ([0, 1] as $status) {
            $products->status(new Request(['id' => 1, 'status' => $status]));
            $this->assertEquals($status, DB::table('items')->where('id', 1)->value('status'));
        }
        DB::table('orders')->insert(['id' => 100001, 'store_id' => 1, 'module_id' => 1,
            'order_type' => 'take_away', 'order_status' => 'pending', 'order_amount' => 100, 'is_guest' => 1]);
        $orders = app(\App\Http\Controllers\Vendor\OrderController::class);
        foreach (['confirmed', 'processing', 'handover'] as $status) {
            $response = $orders->status(new Request(['id' => 100001, 'order_status' => $status]));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame($status, DB::table('orders')->where('id', 100001)->value('order_status'));
            $this->assertNotNull(DB::table('orders')->where('id', 100001)->value($status));
        }
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('whatsapp_contacts'));
    }
}
