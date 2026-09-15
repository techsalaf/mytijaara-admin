<?php

namespace Tests\Architecture;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MySqlHostRegistrationTest extends HostWithoutConciergeTest
{
    private ?\PDO $server = null;
    private ?string $scratch = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') {
            $this->markTestSkipped('Set ISOLATION_MYSQL=1 for the isolated loopback MySQL gate.');
        }
        parent::setUp();
        // Explicit loopback only. Never read application DB credentials or reuse a database.
        $port = (int) (getenv('ISOLATION_MYSQL_PORT') ?: 3306);
        $password = getenv('ISOLATION_MYSQL_PASSWORD') ?: '';
        $this->server = new \PDO("mysql:host=127.0.0.1;port=$port", 'root', $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $name = 'mytijaara_isolation_'.bin2hex(random_bytes(8));
        $this->server->exec("CREATE DATABASE `$name`");
        $this->scratch = $name;
        config(['database.connections.isolation' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
            'database' => $name, 'username' => 'root', 'password' => $password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ], 'database.default' => 'isolation', 'mail.status' => false]);
        RegistrationSchema::create();
        DB::table('business_settings')->insert([
            ['key' => 'toggle_store_registration', 'value' => '1'],
            ['key' => 'recaptcha', 'value' => '{"status":0}'],
            ['key' => 'subscription_business_model', 'value' => '1'],
        ]);
        DB::table('zones')->insert(['id' => 1, 'name' => 'Test zone', 'coordinates' => DB::raw("ST_GeomFromText('POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))',4326)")]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Groceries', 'module_type' => 'grocery']);
        DB::table('module_zone')->insert(['module_id' => 1, 'zone_id' => 1]);
        session(['six_captcha' => 'fixture']);
        Storage::fake('public');
        Mail::fake();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['api.pwnedpasswords.com/*' => \Illuminate\Support\Facades\Http::response('', 200)]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->scratch && preg_match('/^mytijaara_isolation_[a-f0-9]{16}$/D', $this->scratch)) {
                DB::disconnect('isolation');
                $this->server->exec('DROP DATABASE `'.$this->scratch.'`');
            }
        } finally { parent::tearDown(); }
    }

    private function registration(array $overrides = []): Request
    {
        $request = new Request(array_replace([
            'f_name' => 'Test', 'l_name' => 'Vendor', 'email' => 'vendor@example.test',
            'phone' => '+2348000000001', 'password' => 'Fixture-Only!123',
            'name' => ['English store', 'Magasin français'], 'lang' => ['default', 'fr'],
            'address' => ['English address', 'Adresse française'],
            'latitude' => 5, 'longitude' => 5, 'zone_id' => 1, 'module_id' => 1,
            'minimum_delivery_time' => 30, 'maximum_delivery_time' => 40,
            'delivery_time_type' => 'min', 'business_plan' => 'commission-base',
            'custome_recaptcha' => 'fixture',
        ], $overrides));
        $request->files->set('logo', UploadedFile::fake()->image('logo.png'));
        $request->files->set('cover_photo', UploadedFile::fake()->image('cover.png'));
        return $request;
    }

    public function test_valid_web_registration_retains_distinct_translations_without_module_classes(): void
    {
        $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store($this->registration());
        $this->assertArrayHasKey('redirect_url', $response->getData(true));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, Vendor::count());
        $this->assertSame(0, (int) Store::first()->status);
        $this->assertDatabaseHas('translations', ['locale' => 'fr', 'key' => 'name', 'value' => 'Magasin français']);
        $this->assertDatabaseHas('translations', ['locale' => 'fr', 'key' => 'address', 'value' => 'Adresse française']);
        Mail::assertNothingSent();
    }

    public function test_outside_zone_rejects_without_creating_vendor(): void
    {
        $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store($this->registration(['latitude' => 50, 'longitude' => 50]));
        $this->assertSame('zone', $response->getData(true)['errors'][0]['code']);
        $this->assertSame(0, Vendor::count());
    }

    public function test_vendor_api_registration_preserves_response_and_translations_without_module(): void
    {
        $request = $this->registration(['business_plan' => 'commission', 'translations' => json_encode([
            ['locale' => 'en', 'key' => 'name', 'value' => 'API Store'],
            ['locale' => 'en', 'key' => 'address', 'value' => 'API Address'],
            ['locale' => 'fr', 'key' => 'name', 'value' => 'Magasin API'],
            ['locale' => 'fr', 'key' => 'address', 'value' => 'Adresse API'],
        ])]);
        $response = $this->app->make(\App\Http\Controllers\Api\V1\Auth\VendorLoginController::class)->register($request);
        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame(['store_id', 'type', 'message'], array_keys($body));
        $this->assertSame('commission', $body['type']);
        $this->assertNull(Vendor::firstOrFail()->status);
        $this->assertSame('API Store', Store::firstOrFail()->name);
        $this->assertDatabaseHas('translations', ['locale' => 'fr', 'key' => 'name', 'value' => 'Magasin API']);
    }

    public function test_vendor_login_and_mobile_and_web_availability_work_without_module(): void
    {
        $this->app->make(\App\Http\Controllers\VendorController::class)->store($this->registration());
        $vendor = Vendor::firstOrFail();
        $vendor->status = 1;
        $vendor->save();
        $store = Store::firstOrFail();
        $store->status = 1;
        $store->save();
        $login = $this->app->make(\App\Http\Controllers\Api\V1\Auth\VendorLoginController::class)->login(new Request([
            'vendor_type' => 'owner', 'email' => 'vendor@example.test', 'password' => 'Fixture-Only!123',
        ]));
        $this->assertSame(200, $login->getStatusCode());
        $this->assertSame(['token', 'zone_wise_topic', 'module_type'], array_keys($login->getData(true)));
        $this->assertSame('grocery', $login->getData(true)['module_type']);
        $this->assertSame($vendor->fresh()->auth_token, $login->getData(true)['token']);
        $api = $this->app->make(\App\Http\Controllers\Api\V1\Vendor\VendorController::class);
        foreach ([false, true] as $expected) {
            $response = $api->active_status(new Request(['vendor' => $vendor->fresh()]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['message'], array_keys($response->getData(true)));
            $this->assertSame($expected, (bool) $store->fresh()->active);
        }
        $web = $this->app->make(\App\Http\Controllers\Vendor\BusinessSettingsController::class);
        foreach ([false, true] as $expected) {
            $this->actingAs($vendor->fresh(), 'vendor');
            $response = $web->active_status(new Request());
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['message'], array_keys($response->getData(true)));
            $this->assertSame($expected, (bool) $store->fresh()->active);
        }
    }

    public function test_subscription_requires_package_before_persistence(): void
    {
        $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store($this->registration(['business_plan' => 'subscription-base']));
        $this->assertSame('package_id', $response->getData(true)['errors'][0]['code']);
        $this->assertSame(0, Vendor::count());
    }

    public function test_subscription_selection_stays_unactivated(): void
    {
        $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store($this->registration(['business_plan' => 'subscription-base', 'package_id' => 7]));
        $this->assertArrayHasKey('redirect_url', $response->getData(true));
        $this->assertSame('none', Store::first()->store_business_model);
        $this->assertSame(7, (int) Store::first()->package_id);
        $this->assertSame(0, (int) Store::first()->status);
    }

    public function test_registration_email_preferences_are_honoured(): void
    {
        config(['mail.status' => true]);
        DB::table('admins')->insert(['role_id' => 1, 'email' => 'admin@example.test']);
        DB::table('business_settings')->insert([
            ['key' => 'registration_mail_status_store', 'value' => '1'],
            ['key' => 'store_registration_mail_status_admin', 'value' => '1'],
        ]);
        foreach ([['inactive', 'inactive', 0], ['active', 'inactive', 1], ['active', 'active', 2]] as $index => [$vendor, $admin, $count]) {
            Mail::fake();
            DB::table('notification_settings')->delete();
            DB::table('notification_settings')->insert([
                ['type' => 'store', 'key' => 'store_registration', 'mail_status' => $vendor],
                ['type' => 'admin', 'key' => 'store_self_registration', 'mail_status' => $admin],
            ]);
            $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store($this->registration([
                'phone' => '+234800000001'.$index, 'email' => 'vendor'.$index.'@example.test',
            ]));
            $this->assertArrayHasKey('redirect_url', $response->getData(true));
            Mail::assertSentCount($count);
        }
    }

    // The parent validation test creates its own SQLite fixture; this class already has a schema.
    public function test_public_registration_validation_does_not_load_module_classes_or_tables(): void
    {
        $response = $this->app->make(\App\Http\Controllers\VendorController::class)->store(new Request(['custome_recaptcha' => 'fixture']));
        $this->assertArrayHasKey('errors', $response->getData(true));
    }
}
