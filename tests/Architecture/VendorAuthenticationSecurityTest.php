<?php

namespace Tests\Architecture;

use App\Events\VendorApplicationStatusChanged;
use App\Http\Controllers\LoginController;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\VendorApplicationDecisionService;
use App\Services\VendorSecurityTokenService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Modules\Rental\Http\Controllers\Web\Admin\ProviderController;
use PHPUnit\Framework\Attributes\DataProvider;

class VendorAuthenticationSecurityTest extends HostWithoutConciergeTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDatabase();
        RegistrationSchema::create();
        Schema::create('data_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->text('value');
        });
        DB::table('data_settings')->insert(['key' => 'store_login_url', 'value' => 'vendor']);
        Schema::create('password_resets', function (Blueprint $t) {
            $t->string('email');
            $t->string('token');
            $t->string('created_by');
            $t->timestamp('created_at');
        });
        Schema::table('vendors', function (Blueprint $t) {
            $t->string('remember_token')->nullable();
            $t->string('login_remember_token')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::table('stores', fn (Blueprint $t) => $t->timestamp('deleted_at')->nullable());
        Schema::create('vendor_employees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('vendor_id');
            $t->unsignedBigInteger('store_id');
            $t->string('email');
            $t->string('password');
            $t->string('auth_token')->nullable();
            $t->string('remember_token')->nullable();
            $t->string('login_remember_token')->nullable();
            $t->integer('status')->default(1);
            $t->integer('is_logged_in')->default(1);
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('store_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->integer('status');
            $t->date('expiry_date');
            $t->boolean('mobile_app')->default(true);
            $t->timestamps();
        });
        (require base_path('database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php'))->up();
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Grocery', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('zones')->insert(['id' => 1, 'name' => 'Zone', 'status' => 1]);
        DB::table('vendors')->insert(['id' => 1, 'email' => 'owner@example.test', 'phone' => '2348001111111',
            'password' => Hash::make('Fixture-Only!123'), 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stores')->insert(['id' => 1, 'vendor_id' => 1, 'name' => 'Fixture', 'phone' => '2348001111111',
            'module_id' => 1, 'zone_id' => 1, 'status' => 1, 'store_business_model' => 'commission']);
        config(['mail.status' => false, 'app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    protected function configureDatabase(): void {}

    public function test_rental_admin_status_revokes_tokens_and_application_decision_is_shared(): void
    {
        DB::table('vendors')->update(['auth_token' => str_repeat('r', 120)]);
        $controller = app(ProviderController::class);
        $request = new Request(['status' => 0]);
        $controller->status($request, 1);
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->assertSame(0, (int) DB::table('stores')->value('status'));
        DB::table('vendors')->update(['status' => null, 'auth_token' => str_repeat('r', 120)]);
        Schema::table('stores', fn (Blueprint $t) => $t->text('comment')->nullable());
        Event::fake([VendorApplicationStatusChanged::class]);
        $controller->approveOrDeny(new Request(['id' => 1, 'status' => 1]));
        $this->assertSame(1, (int) DB::table('vendors')->value('status'));
        $this->assertNull(DB::table('vendors')->value('auth_token'));
    }

    public function test_rental_admin_auth_state_changes_use_post_routes_and_csrf_forms(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes());
        foreach (['admin.rental.provider.approve-or-deny', 'admin.rental.provider.status-by-store'] as $name) {
            $route = $routes->first(fn ($r) => $r->getName() === $name);
            $this->assertNotNull($route);
            $this->assertSame(['POST'], $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
        }
        foreach (['new-request', 'new-request-details', 'list'] as $view) {
            $source = file_get_contents(base_path('Modules/Rental/Resources/views/admin/provider/'.$view.'.blade.php'));
            $this->assertStringContainsString('method="post"', $source);
            $this->assertStringContainsString('@csrf', $source);
        }
    }

    private function login(array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/vendor/login', array_replace([
            'email' => 'owner@example.test', 'password' => 'Fixture-Only!123', 'vendor_type' => 'owner',
        ], $overrides), ['X-Vendor-Auth-Version' => '2']);
    }

    private function headers(string $token, string $type = 'owner'): array
    {
        return ['Authorization' => 'Bearer '.$token, 'vendorType' => $type];
    }

    public static function deniedStates(): array
    {
        return [
            'pending commission' => [null, 0, 'commission', null, false],
            'pending subscription' => [null, 0, 'subscription', null, false],
            'pending none' => [null, 0, 'none', null, true],
            'rejected none' => [0, 0, 'none', 'Rejected', false],
            'inactive vendor' => [0, 1, 'commission', null, false],
            'suspended store' => [1, 0, 'commission', null, false],
            'suspended none' => [1, 0, 'none', null, false],
            'approved none' => [1, 1, 'none', null, true],
            'unknown business model' => [1, 1, 'other', null, false],
        ];
    }

    #[DataProvider('deniedStates')]
    public function test_ineligible_states_never_issue_full_tokens($vendorStatus, $storeStatus, $model, $note, $restricted): void
    {
        DB::table('vendors')->where('id', 1)->update(['status' => $vendorStatus, 'rejection_note' => $note]);
        DB::table('stores')->where('id', 1)->update(['status' => $storeStatus, 'store_business_model' => $model]);
        $response = $this->login();
        $response->assertStatus($restricted ? 200 : 403);
        $this->assertNull($response->json('token'));
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->assertSame($restricted, $response->json('pre_activation.token') !== null);
        $this->assertFalse(auth('vendor')->check());
    }

    public function test_approved_login_and_logout_use_real_middleware(): void
    {
        $response = $this->login()->assertOk();
        $this->assertSame(['token', 'zone_wise_topic', 'module_type'], array_keys($response->json()));
        $token = $response->json('token');
        $this->assertSame(120, strlen($token));
        $this->postJson('/api/v1/vendor/logout', [], $this->headers($token))->assertOk();
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->postJson('/api/v1/vendor/logout', [], $this->headers($token))->assertUnauthorized();
    }

    public function test_wrong_credentials_do_not_issue_restricted_token(): void
    {
        DB::table('vendors')->update(['status' => null]);
        DB::table('stores')->update(['status' => 0, 'store_business_model' => 'none']);
        $this->login(['password' => 'Incorrect!123'])->assertUnauthorized();
        $this->assertSame(0, DB::table('vendor_security_tokens')->count());
    }

    public function test_legacy_client_gets_safe_error_instead_of_incompatible_token_envelope(): void
    {
        DB::table('stores')->update(['store_business_model' => 'none']);
        $this->postJson('/api/v1/auth/vendor/login', [
            'email' => 'owner@example.test', 'password' => 'Fixture-Only!123', 'vendor_type' => 'owner',
        ])->assertForbidden()->assertJsonPath('errors.0.code', 'subscription_setup_required');
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->assertSame(0, DB::table('vendor_security_tokens')->count());
    }

    public function test_existing_token_is_rejected_after_status_change(): void
    {
        $token = $this->login()->json('token');
        DB::table('stores')->where('id', 1)->update(['status' => 0]);
        $this->postJson('/api/v1/vendor/logout', [], $this->headers($token))->assertUnauthorized()->assertJsonPath('errors.0.code', 'vendor_suspended');
        $this->assertNull(DB::table('vendors')->value('auth_token'));
    }

    public function test_old_tokens_rejected_for_rejected_and_deleted_accounts(): void
    {
        foreach (['rejected', 'deleted', 'inactive'] as $state) {
            DB::table('vendors')->where('id', 1)->update(['status' => $state === 'deleted' ? 1 : 0,
                'rejection_note' => $state === 'rejected' ? 'Denied' : null, 'deleted_at' => $state === 'deleted' ? now() : null,
                'auth_token' => str_repeat('a', 120)]);
            $this->postJson('/api/v1/vendor/logout', [], $this->headers(str_repeat('a', 120)))->assertUnauthorized();
        }
    }

    public function test_soft_deleted_store_fails_closed(): void
    {
        DB::table('stores')->update(['deleted_at' => now()]);
        $this->login()->assertForbidden();
    }

    public function test_temporary_store_closure_does_not_lock_owner_out(): void
    {
        DB::table('stores')->update(['active' => 0]);
        $this->login()->assertOk();
    }

    public function test_subscription_expiry_and_mobile_entitlement(): void
    {
        DB::table('stores')->update(['store_business_model' => 'subscription']);
        DB::table('store_subscriptions')->insert(['store_id' => 1, 'status' => 1, 'expiry_date' => now()->addDay()->toDateString(), 'mobile_app' => 1]);
        $this->assertNotNull($this->login()->assertOk()->json('token'));
        DB::table('store_subscriptions')->update(['mobile_app' => 0]);
        $this->login()->assertForbidden();
        DB::table('store_subscriptions')->update(['mobile_app' => 1, 'expiry_date' => now()->subDay()->toDateString()]);
        $this->assertNull($this->login()->assertOk()->json('token'));
    }

    public function test_restricted_token_only_changes_own_plan_and_never_activates(): void
    {
        DB::table('vendors')->update(['status' => null]);
        DB::table('stores')->update(['status' => 0, 'store_business_model' => 'none']);
        $raw = $this->login()->json('pre_activation.token');
        $this->postJson('/api/v1/vendor/logout', [], $this->headers($raw))->assertUnauthorized();
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 1, 'business_plan' => 'commission'], $this->headers($raw))->assertOk();
        $this->assertSame('commission', DB::table('stores')->value('store_business_model'));
        $this->assertSame(0, DB::table('stores')->value('status'));
        $this->assertNull(DB::table('vendors')->value('status'));
        $this->assertNotNull(DB::table('vendor_security_tokens')->value('consumed_at'));
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 1, 'business_plan' => 'commission'], $this->headers($raw))->assertForbidden();
        $this->login()->assertForbidden();
    }

    public function test_subscription_routes_deny_missing_auth_and_cross_store(): void
    {
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 1, 'business_plan' => 'commission'])->assertForbidden();
        $token = $this->login()->json('token');
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 999, 'business_plan' => 'commission'], $this->headers($token))->assertForbidden();
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 1, 'business_plan' => 'commission'], $this->headers($token, 'alien'))->assertUnauthorized();
    }

    public function test_restricted_token_rejected_on_cancel_expiry_and_suspension(): void
    {
        DB::table('stores')->update(['store_business_model' => 'none']);
        $raw = $this->login()->json('pre_activation.token');
        $this->postJson('/api/v1/vendor/cancel-subscription', ['store_id' => 1], $this->headers($raw))->assertForbidden();
        DB::table('vendor_security_tokens')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 1, 'business_plan' => 'commission'], $this->headers($raw))->assertForbidden();
        $raw = $this->login()->json('pre_activation.token');
        DB::table('stores')->update(['status' => 0]);
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 1, 'business_plan' => 'commission'], $this->headers($raw))->assertForbidden();
    }

    private function reset(string $raw)
    {
        return $this->putJson('/api/v1/auth/vendor/reset-password', [
            'email' => 'owner@example.test', 'reset_token' => $raw, 'password' => 'Changed-Only!456', 'confirm_password' => 'Changed-Only!456',
        ]);
    }

    public function test_reset_pending_password_does_not_approve_and_revokes_tokens(): void
    {
        DB::table('vendors')->update(['status' => null, 'auth_token' => str_repeat('x', 120)]);
        DB::table('stores')->update(['status' => 0]);
        $tokens = app(VendorSecurityTokenService::class);
        $raw = $tokens->issue(Vendor::first(), VendorSecurityTokenService::API_RESET);
        $this->assertNotSame($raw, DB::table('vendor_security_tokens')->value('token_hash'));
        $this->reset($raw)->assertOk();
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->assertNull(DB::table('vendors')->value('status'));
        $this->assertTrue(Hash::check('Changed-Only!456', DB::table('vendors')->value('password')));
        $this->login(['password' => 'Changed-Only!456'])->assertForbidden();
        $this->reset($raw)->assertForbidden();
    }

    public function test_reset_expiry_and_purpose_confusion_fail(): void
    {
        $tokens = app(VendorSecurityTokenService::class);
        $vendor = Vendor::first();
        $raw = $tokens->issue($vendor, VendorSecurityTokenService::API_RESET);
        DB::table('vendor_security_tokens')->update(['expires_at' => now()->subSecond()]);
        $this->reset($raw)->assertForbidden();
        $web = $tokens->issue($vendor, VendorSecurityTokenService::WEB_RESET);
        $this->reset($web)->assertForbidden();
        $this->assertFalse($tokens->resetPassword($web, 'vendor_onboarding_password', 'Changed!123', 1));
        $this->assertTrue(Hash::check('Fixture-Only!123', $vendor->fresh()->password));
    }

    public function test_reset_otp_verification_enforces_expiry(): void
    {
        $raw = app(VendorSecurityTokenService::class)->issue(Vendor::first(), VendorSecurityTokenService::API_RESET);
        $this->postJson('/api/v1/auth/vendor/verify-token', ['email' => 'owner@example.test', 'reset_token' => $raw])->assertOk();
        DB::table('vendor_security_tokens')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/auth/vendor/verify-token', ['email' => 'owner@example.test', 'reset_token' => $raw])->assertForbidden();
    }

    public function test_forgot_password_response_does_not_enumerate(): void
    {
        $known = $this->postJson('/api/v1/auth/vendor/forgot-password', ['email' => 'owner@example.test'])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/vendor/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
    }

    public function test_web_login_attempt_uses_same_policy_without_pending_session(): void
    {
        $controller = app(LoginController::class);
        DB::table('vendors')->update(['status' => null]);
        DB::table('stores')->update(['status' => 0]);
        $this->assertFalse($controller->login_attemp('vendor', 'owner@example.test', 'Fixture-Only!123', '127.0.0.1'));
        $this->assertFalse(auth('vendor')->check());
        DB::table('vendors')->update(['status' => 1]);
        DB::table('stores')->update(['status' => 1]);
        $this->assertSame('vendor', $controller->login_attemp('vendor', 'owner@example.test', 'Fixture-Only!123', '127.0.0.1'));
        DB::table('stores')->update(['status' => 0]);
        $this->get('/vendor-panel')->assertRedirect();
        $this->assertFalse(auth('vendor')->check());
    }

    public function test_registration_web_routes_require_session_binding(): void
    {
        $this->get('/vendor/business-plan?store_id=1&business_plan=commission-base')->assertForbidden();
        $this->assertSame('commission', DB::table('stores')->value('store_business_model'));
    }

    public function test_audit_is_redacted_dry_run_and_revocation_is_idempotent(): void
    {
        DB::table('vendors')->update(['status' => null, 'auth_token' => str_repeat('secret', 20)]);
        $this->artisan('vendor:tokens-audit')->assertSuccessful();
        $this->assertNotNull(DB::table('vendors')->value('auth_token'));
        $this->artisan('vendor:tokens-audit', ['--revoke' => true, '--execute' => true])->assertSuccessful();
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->artisan('vendor:tokens-audit', ['--revoke' => true, '--execute' => true])->assertSuccessful();
    }

    public function test_employee_login_and_tokens_recheck_owner_eligibility(): void
    {
        DB::table('vendor_employees')->insert(['vendor_id' => 1, 'store_id' => 1, 'email' => 'employee@example.test',
            'password' => Hash::make('Fixture-Only!123'), 'auth_token' => str_repeat('e', 120)]);
        DB::table('vendors')->update(['status' => null]);
        DB::table('stores')->update(['status' => 0]);
        $this->login(['email' => 'employee@example.test', 'vendor_type' => 'employee'])->assertForbidden();
        $this->postJson('/api/v1/vendor/logout', [], $this->headers(str_repeat('e', 120), 'employee'))->assertUnauthorized();
        $this->assertNull(DB::table('vendor_employees')->value('auth_token'));
    }

    public function test_web_login_route_cannot_leak_payment_view_before_password_check(): void
    {
        DB::table('business_settings')->insert(['key' => 'recaptcha', 'value' => '{"status":0}']);
        DB::table('vendors')->update(['status' => null]);
        DB::table('stores')->update(['status' => 0, 'store_business_model' => 'none']);
        $this->withSession(['six_captcha' => 'fixture'])->post('/login_submit', [
            'email' => 'owner@example.test', 'password' => 'Incorrect!123', 'role' => 'vendor', 'custome_recaptcha' => 'fixture',
        ])->assertRedirect();
        $this->assertNull(session('vendor_registration_store_id'));
        $this->withSession(['six_captcha' => 'fixture'])->post('/login_submit', [
            'email' => 'owner@example.test', 'password' => 'Fixture-Only!123', 'role' => 'vendor', 'custome_recaptcha' => 'fixture',
        ])->assertRedirect();
        $this->assertSame(1, session('vendor_registration_store_id'));
        $this->assertFalse(auth('vendor')->check());
    }

    public function test_pending_web_reset_route_is_single_use_without_activation(): void
    {
        DB::table('vendors')->update(['status' => null, 'auth_token' => str_repeat('x', 120)]);
        DB::table('stores')->update(['status' => 0]);
        $raw = app(VendorSecurityTokenService::class)->issue(Vendor::first(), VendorSecurityTokenService::WEB_RESET);
        $this->post('/reset-password-submit', ['reset_token' => $raw, 'password' => 'Changed-Only!456', 'confirm_password' => 'Changed-Only!456'])->assertRedirect();
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->assertNull(DB::table('vendors')->value('status'));
        $this->assertFalse(app(VendorSecurityTokenService::class)->resetPassword($raw, VendorSecurityTokenService::WEB_RESET, 'Other-Only!789'));
    }

    public function test_expired_and_legacy_vendor_web_reset_tokens_cannot_change_password(): void
    {
        $raw = app(VendorSecurityTokenService::class)->issue(Vendor::first(), VendorSecurityTokenService::WEB_RESET);
        DB::table('vendor_security_tokens')->update(['expires_at' => now()->subSecond()]);
        $this->post('/reset-password-submit', ['reset_token' => $raw, 'password' => 'Changed-Only!456', 'confirm_password' => 'Changed-Only!456'])->assertRedirect();
        DB::table('password_resets')->insert(['email' => 'owner@example.test', 'token' => 'legacy-raw-vendor-token', 'created_by' => 'vendor', 'created_at' => now()]);
        $this->post('/reset-password-submit', ['reset_token' => 'legacy-raw-vendor-token', 'password' => 'Changed-Only!456', 'confirm_password' => 'Changed-Only!456'])->assertRedirect();
        $this->assertTrue(Hash::check('Fixture-Only!123', DB::table('vendors')->value('password')));
    }

    public function test_reset_attempts_are_account_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/vendor/verify-token', ['email' => 'owner@example.test', 'reset_token' => 'wrong'])->assertForbidden();
        }
        $this->postJson('/api/v1/auth/vendor/verify-token', ['email' => 'owner@example.test', 'reset_token' => 'wrong'])->assertStatus(429);
    }

    public function test_superseded_setup_and_reset_tokens_cannot_be_reused(): void
    {
        $tokens = app(VendorSecurityTokenService::class);
        $vendor = Vendor::first();
        $store = Store::first();
        $first = $tokens->issue($vendor, VendorSecurityTokenService::PRE_ACTIVATION, $store);
        $second = $tokens->issue($vendor, VendorSecurityTokenService::PRE_ACTIVATION, $store);
        $this->assertNull($tokens->lookup($first, VendorSecurityTokenService::PRE_ACTIVATION));
        $this->assertNotNull($tokens->lookup($second, VendorSecurityTokenService::PRE_ACTIVATION));
        $this->assertNotNull($this->login()->json('token'));
        $this->assertNull($tokens->lookup($second, VendorSecurityTokenService::PRE_ACTIVATION));
    }

    public function test_application_decisions_revoke_old_tokens_before_reapproval(): void
    {
        DB::table('vendors')->update(['status' => null, 'auth_token' => str_repeat('x', 120)]);
        DB::table('stores')->update(['status' => 0]);
        Event::fake([VendorApplicationStatusChanged::class]);
        app(VendorApplicationDecisionService::class)->decide(1, 1);
        $this->assertNull(DB::table('vendors')->value('auth_token'));
        $this->postJson('/api/v1/vendor/logout', [], $this->headers(str_repeat('x', 120)))->assertUnauthorized();
        $token = $this->login()->json('token');
        app(VendorApplicationDecisionService::class)->decide(1, 0, 'Rejected');
        $this->postJson('/api/v1/vendor/logout', [], $this->headers($token))->assertUnauthorized();
    }

    public function test_password_reset_rolls_back_password_and_consumption_on_database_failure(): void
    {
        $tokens = app(VendorSecurityTokenService::class);
        $raw = $tokens->issue(Vendor::first(), VendorSecurityTokenService::API_RESET);
        $fired = false;
        DB::connection()->beforeExecuting(function ($sql) use (&$fired) {
            if (! $fired && str_contains($sql, 'vendor_security_tokens') && str_contains($sql, 'consumed_at') && str_starts_with($sql, 'update')) {
                $fired = true;
                throw new \RuntimeException('Fixture persistence failure');
            }
        });
        try {
            $tokens->resetPassword($raw, VendorSecurityTokenService::API_RESET, 'Changed-Only!456', 1);
            $this->fail('Injected database failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fixture persistence failure', $e->getMessage());
        }
        $this->assertTrue(Hash::check('Fixture-Only!123', DB::table('vendors')->value('password')));
        $this->assertNull(DB::table('vendor_security_tokens')->value('consumed_at'));
        $this->assertNotNull($tokens->lookup($raw, VendorSecurityTokenService::API_RESET, 1));
    }

    public function test_restricted_token_cannot_be_padded_into_full_token_or_cross_store(): void
    {
        DB::table('stores')->update(['store_business_model' => 'none']);
        $raw = $this->login()->json('pre_activation.token');
        $this->postJson('/api/v1/vendor/logout', [], $this->headers(str_pad($raw, 120, 'x')))->assertUnauthorized();
        $this->postJson('/api/v1/vendor/business_plan', ['store_id' => 999, 'business_plan' => 'commission'], $this->headers($raw))->assertForbidden();
        $this->assertSame('none', DB::table('stores')->value('store_business_model'));
    }

    public function test_identity_changes_invalidate_outstanding_reset_and_setup_tokens(): void
    {
        $tokens = app(VendorSecurityTokenService::class);
        $vendor = Vendor::first();
        $reset = $tokens->issue($vendor, VendorSecurityTokenService::WEB_RESET);
        $setup = $tokens->issue($vendor, VendorSecurityTokenService::PRE_ACTIVATION, Store::first());
        DB::table('vendors')->update(['email' => 'changed@example.test', 'phone' => '+2348099999999']);
        $this->assertNull($tokens->lookup($reset, VendorSecurityTokenService::WEB_RESET));
        $this->assertNull($tokens->lookup($setup, VendorSecurityTokenService::PRE_ACTIVATION));
        $this->assertFalse($tokens->resetPassword($reset, VendorSecurityTokenService::WEB_RESET, 'Changed-Only!456'));
        $this->assertTrue(Hash::check('Fixture-Only!123', DB::table('vendors')->value('password')));
    }
}
