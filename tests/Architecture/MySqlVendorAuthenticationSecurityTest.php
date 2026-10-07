<?php

namespace Tests\Architecture;

use App\Services\VendorAuthenticationEligibility;
use App\Services\VendorAuthenticationService;
use Illuminate\Support\Facades\DB;

class MySqlVendorAuthenticationSecurityTest extends VendorAuthenticationSecurityTest
{
    private ?\PDO $server = null;

    private ?string $scratch = null;

    protected function setUp(): void
    {
        if (getenv('ISOLATION_MYSQL') !== '1') {
            $this->markTestSkipped('Set ISOLATION_MYSQL=1 for loopback-only scratch database tests.');
        }
        parent::setUp();
    }

    protected function configureDatabase(): void
    {
        $port = (int) (getenv('ISOLATION_MYSQL_PORT') ?: 3306);
        $password = getenv('ISOLATION_MYSQL_PASSWORD') ?: '';
        $this->server = new \PDO("mysql:host=127.0.0.1;port=$port", 'root', $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->scratch = 'mytijaara_auth_isolation_'.bin2hex(random_bytes(8));
        fwrite(STDOUT, 'LOCAL disposable database: 127.0.0.1:'.$port.'/'.$this->scratch.PHP_EOL);
        $this->server->exec('CREATE DATABASE `'.$this->scratch.'`');
        config(['database.default' => 'auth_isolation', 'database.connections.auth_isolation' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => $this->scratch,
            'username' => 'root', 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true,
        ]]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->scratch && preg_match('/^mytijaara_auth_isolation_[a-f0-9]{16}$/D', $this->scratch)) {
                DB::disconnect('auth_isolation');
                $this->server->exec('DROP DATABASE `'.$this->scratch.'`');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_status_reversal_cannot_write_during_locked_issuance_and_old_token_immediately_fails(): void
    {
        $server = $this->server;
        $database = $this->scratch;
        $blocked = false;
        $server->exec('SET SESSION innodb_lock_wait_timeout=1');
        $policy = \Mockery::mock(VendorAuthenticationEligibility::class)->makePartial();
        $policy->shouldReceive('evaluate')->once()->andReturnUsing(function ($vendor, $store, $channel, $employee) use ($server, $database, &$blocked) {
            try {
                $server->exec('UPDATE `'.$database.'`.stores SET status=0 WHERE id=1');
            } catch (\PDOException $error) {
                $blocked = (int) ($error->errorInfo[1] ?? 0) === 1205;
            }

            return (new VendorAuthenticationEligibility)->evaluate($vendor, $store, $channel, $employee);
        });
        $this->app->instance(VendorAuthenticationEligibility::class, $policy);
        $result = app(VendorAuthenticationService::class)->authenticate('owner@example.test', 'Fixture-Only!123', 'owner');
        $this->assertTrue($blocked, 'Concurrent status reversal must wait for store lock.');
        $this->assertNotNull($result['token']);
        $server->exec('UPDATE `'.$database.'`.stores SET status=0 WHERE id=1');
        $this->app->instance(VendorAuthenticationEligibility::class, new VendorAuthenticationEligibility);
        $this->postJson('/api/v1/vendor/logout', [], ['Authorization' => 'Bearer '.$result['token'], 'vendorType' => 'owner'])->assertUnauthorized();
        $this->assertNull(DB::table('vendors')->value('auth_token'));
    }

    public function test_two_login_attempts_for_pending_vendor_leave_no_full_tokens(): void
    {
        DB::table('vendors')->update(['status' => null]);
        DB::table('stores')->update(['status' => 0]);
        $server = $this->server;
        $database = $this->scratch;
        $blocked = false;
        $server->exec('SET SESSION innodb_lock_wait_timeout=1');
        $policy = \Mockery::mock(VendorAuthenticationEligibility::class)->makePartial();
        $policy->shouldReceive('evaluate')->once()->andReturnUsing(function ($vendor, $store, $channel, $employee) use ($server, $database, &$blocked) {
            try {
                // The competing writer cannot install a token while this login holds the vendor lock.
                $server->exec('UPDATE `'.$database.'`.vendors SET auth_token=REPEAT("x",120) WHERE id=1');
            } catch (\PDOException $error) {
                $blocked = (int) ($error->errorInfo[1] ?? 0) === 1205;
            }

            return (new VendorAuthenticationEligibility)->evaluate($vendor, $store, $channel, $employee);
        });
        $this->app->instance(VendorAuthenticationEligibility::class, $policy);
        $first = app(VendorAuthenticationService::class)->authenticate('owner@example.test', 'Fixture-Only!123', 'owner');
        $this->assertTrue($blocked);
        $this->assertFalse($first['decision']->eligible);
        $this->app->instance(VendorAuthenticationEligibility::class, new VendorAuthenticationEligibility);
        $second = app(VendorAuthenticationService::class)->authenticate('owner@example.test', 'Fixture-Only!123', 'owner');
        $this->assertFalse($second['decision']->eligible);
        $this->assertNull(DB::table('vendors')->value('auth_token'));
    }
}
