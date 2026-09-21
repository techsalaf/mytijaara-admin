<?php
namespace Modules\WhatsAppVendorConcierge\tests\Integration;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\OrderMutationService;
use Tests\Architecture\FullCoreDatabase;

final class MySqlOrderParityTest extends \Tests\TestCase
{
    private ?FullCoreDatabase $fixture = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') $this->markTestSkipped('Set ISOLATION_MYSQL=1 for local accounting/stock integration.');
        parent::setUp();
        $this->fixture = new FullCoreDatabase();
        $this->fixture->create();
        foreach (glob(base_path('Modules/WhatsAppVendorConcierge/database/migrations/*.php')) as $migration) (require $migration)->up();
        config(['cache.default' => 'array', 'mail.status' => false, 'canceled_by_store' => true,
            'order_confirmation_model' => 'store', 'module.grocery.stock' => true]);
        Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        DB::table('business_settings')->insert([
            ['key' => 'dm_tips_status', 'value' => '0'], ['key' => 'wallet_add_refund', 'value' => '1'],
            ['key' => 'wallet_status', 'value' => '1'], ['key' => 'admin_commission', 'value' => '10'],
            ['key' => 'ref_earning_status', 'value' => '0'], ['key' => 'loyalty_point_status', 'value' => '0'],
        ]);
        DB::table('admins')->insert(['id' => 1, 'role_id' => 1, 'f_name' => 'Fixture', 'email' => 'admin@example.test', 'password' => bcrypt('Fixture-Only!123')]);
        DB::table('vendors')->insert(['id' => 1, 'f_name' => 'Vendor', 'phone' => '2348000000001', 'email' => 'vendor@example.test', 'status' => 1, 'password' => bcrypt('Fixture-Only!123')]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('stores')->insert(['id' => 1, 'vendor_id' => 1, 'name' => 'Fixture store', 'module_id' => 1,
            'status' => 1, 'active' => 1, 'store_business_model' => 'commission', 'comission' => 10, 'phone' => '2348000000001']);
        DB::table('users')->insert(['id' => 1, 'f_name' => 'Customer', 'phone' => '2348000000002', 'wallet_balance' => 0]);
        DB::table('items')->insert(['id' => 1, 'store_id' => 1, 'module_id' => 1, 'name' => 'Rice', 'price' => 100,
            'stock' => 8, 'variations' => '[{"type":"Small","price":100,"stock":8}]']);
        DB::table('admin_wallets')->insert(['admin_id' => 1, 'digital_received' => 1000]);
    }

    protected function tearDown(): void
    {
        try { $this->fixture?->destroy(); } finally { parent::tearDown(); }
    }

    public function test_paid_cancellation_restores_variant_stock_and_refunds_wallet_once(): void
    {
        DB::table('orders')->insert(['id' => 100001, 'store_id' => 1, 'module_id' => 1, 'user_id' => 1,
            'order_type' => 'delivery', 'order_status' => 'pending', 'payment_method' => 'digital_payment',
            'payment_status' => 'paid', 'order_amount' => 200, 'is_guest' => 0]);
        DB::table('order_details')->insert(['order_id' => 100001, 'item_id' => 1, 'quantity' => 2, 'price' => 100,
            'variation' => '[{"type":"Small","price":100,"stock":8}]']);
        $service = app(OrderMutationService::class);
        $service->transitionStatus(Order::findOrFail(100001), 1, 'canceled', ['reason' => 'Item unavailable']);
        $this->assertDatabaseHas('orders', ['id' => 100001, 'order_status' => 'canceled', 'canceled_by' => 'store']);
        $this->assertEquals(10, DB::table('items')->where('id', 1)->value('stock'));
        $this->assertSame(10, json_decode(DB::table('items')->where('id', 1)->value('variations'), true)[0]['stock']);
        $this->assertEquals(800, DB::table('admin_wallets')->where('admin_id', 1)->value('digital_received'));
        $this->assertEquals(200, DB::table('users')->where('id', 1)->value('wallet_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', 1)->count());
        $service->transitionStatus(Order::findOrFail(100001), 1, 'canceled', ['reason' => 'Item unavailable']);
        $this->assertEquals(10, DB::table('items')->where('id', 1)->value('stock'));
        $this->assertEquals(200, DB::table('users')->where('id', 1)->value('wallet_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', 1)->count());

        // A second PHP process reaches the same row while the first transaction
        // owns its lock. Once released, it must observe cancellation and do no work.
        DB::table('orders')->insert(['id' => 100003, 'store_id' => 1, 'module_id' => 1, 'user_id' => 1,
            'order_type' => 'delivery', 'order_status' => 'pending', 'payment_method' => 'digital_payment',
            'payment_status' => 'paid', 'order_amount' => 200, 'is_guest' => 0]);
        DB::table('order_details')->insert(['order_id' => 100003, 'item_id' => 1, 'quantity' => 2, 'price' => 100,
            'variation' => '[{"type":"Small","price":100,"stock":10}]']);
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.connections.core_fixture' => json_decode(getenv('ISOLATION_CONNECTION'), true),
            'database.default' => 'core_fixture', 'cache.default' => 'array', 'mail.status' => false,
            'canceled_by_store' => true, 'module.grocery.stock' => true]);
        Illuminate\Support\Facades\Http::preventStrayRequests();
        Illuminate\Support\Facades\Queue::fake();
        Illuminate\Support\Facades\Mail::fake();
        $order = App\Models\Order::findOrFail(100003);
        echo "READY\n"; flush();
        app(Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\OrderMutationService::class)
            ->transitionStatus($order, 1, 'canceled', ['reason' => 'Unavailable']);
        echo "DONE\n";
        PHP;
        $child = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code], base_path(),
            ['ISOLATION_CONNECTION' => json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR)], timeout: 60);
        DB::beginTransaction();
        try {
            Order::whereKey(100003)->lockForUpdate()->firstOrFail();
            $child->start();
            $this->assertTrue($child->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            usleep(100000);
            $this->assertTrue($child->isRunning(), 'Competing mutation must wait for the existing row lock');
            $service->transitionStatus(Order::findOrFail(100003), 1, 'canceled', ['reason' => 'Unavailable']);
            DB::commit();
            $child->wait();
            $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            $this->assertStringContainsString('DONE', $child->getOutput());
            $this->assertEquals(12, DB::table('items')->where('id', 1)->value('stock'));
            $this->assertEquals(400, DB::table('users')->where('id', 1)->value('wallet_balance'));
            $this->assertEquals(600, DB::table('admin_wallets')->where('admin_id', 1)->value('digital_received'));
            $this->assertSame(2, DB::table('wallet_transactions')->where('user_id', 1)->count());
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
            if ($child->isRunning()) $child->stop();
        }
    }

    public function test_verified_delivery_creates_accounting_and_counts_once(): void
    {
        config(['order_delivery_verification' => true]);
        DB::table('stores')->where('id', 1)->update(['self_delivery_system' => 1]);
        DB::table('delivery_men')->insert(['id' => 1, 'phone' => '2348000000003', 'password' => bcrypt('Fixture-Only!123'),
            'identity_image' => '[]', 'current_orders' => 1, 'order_count' => 0, 'type' => 'salary_based']);
        DB::table('orders')->insert(['id' => 100002, 'store_id' => 1, 'module_id' => 1, 'user_id' => 1,
            'order_type' => 'delivery', 'order_status' => 'handover', 'payment_method' => 'cash_on_delivery',
            'payment_status' => 'unpaid', 'order_amount' => 200, 'is_guest' => 0, 'otp' => '4821', 'delivery_man_id' => 1]);
        DB::table('order_details')->insert(['order_id' => 100002, 'item_id' => 1, 'quantity' => 2, 'price' => 100]);
        $service = app(OrderMutationService::class);
        try { $service->transitionStatus(Order::findOrFail(100002), 1, 'delivered', ['otp' => '0000']); $this->fail('Invalid OTP accepted'); }
        catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame(0, DB::table('order_transactions')->where('order_id', 100002)->count());
        }
        // Fail after accounting and counters have run: the enclosing adapter must
        // roll everything back, including nested transactions in host helpers.
        DB::unprepared("CREATE TRIGGER reject_fixture_delivery BEFORE UPDATE ON orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture delivery persistence failure'");
        try {
            $service->transitionStatus(Order::findOrFail(100002), 1, 'delivered', ['otp' => '4821']);
            $this->fail('Injected persistence failure was swallowed');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('fixture delivery persistence failure', $error->getMessage());
            $this->assertSame(0, DB::table('order_transactions')->count());
            $this->assertSame(0, DB::table('store_wallets')->count());
            $this->assertDatabaseHas('orders', ['id' => 100002, 'order_status' => 'handover', 'payment_status' => 'unpaid']);
            $this->assertEquals(0, DB::table('stores')->where('id', 1)->value('order_count'));
            $this->assertEquals(1, DB::table('delivery_men')->where('id', 1)->value('current_orders'));
        } finally {
            DB::unprepared('DROP TRIGGER reject_fixture_delivery');
        }
        $service->transitionStatus(Order::findOrFail(100002), 1, 'delivered', ['otp' => '4821']);
        $this->assertDatabaseHas('orders', ['id' => 100002, 'order_status' => 'delivered', 'payment_status' => 'paid']);
        $this->assertNotNull(DB::table('orders')->where('id', 100002)->value('delivered'));
        $this->assertSame(1, DB::table('order_transactions')->where('order_id', 100002)->count());
        $this->assertDatabaseHas('order_transactions', ['order_id' => 100002, 'store_amount' => 180, 'admin_commission' => 20]);
        foreach (['items', 'stores', 'users', 'delivery_men'] as $table) $this->assertEquals(1, DB::table($table)->where('id', 1)->value('order_count'));
        $this->assertEquals(0, DB::table('delivery_men')->where('id', 1)->value('current_orders'));
        $service->transitionStatus(Order::findOrFail(100002), 1, 'delivered', ['otp' => '4821']);
        $this->assertSame(1, DB::table('order_transactions')->where('order_id', 100002)->count());
        $this->assertEquals(1, DB::table('stores')->where('id', 1)->value('order_count'));
    }
}
