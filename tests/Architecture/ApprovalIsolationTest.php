<?php
namespace Tests\Architecture;

use App\Events\VendorApplicationStatusChanged;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\VendorApplicationDecisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApprovalIsolationTest extends HostWithoutConciergeTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('business_settings', function (Blueprint $t) { $t->id(); $t->string('key'); $t->text('value')->nullable(); $t->timestamps(); });
        Schema::create('storages', function (Blueprint $t) { $t->id(); $t->string('data_type'); $t->string('data_id'); $t->string('key')->nullable(); $t->string('value')->nullable(); $t->timestamps(); });
        Schema::create('translations', function (Blueprint $t) { $t->id(); $t->string('translationable_type'); $t->integer('translationable_id'); $t->string('locale'); $t->string('key'); $t->text('value')->nullable(); });
        foreach (['vendors', 'stores'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id(); $t->integer('status')->nullable(); $t->timestamps();
                if ($name === 'vendors') { $t->text('rejection_note')->nullable(); }
                else { $t->integer('vendor_id'); $t->string('store_business_model')->default('commission'); }
            });
        }
        Schema::create('store_subscriptions', function (Blueprint $t) {
            $t->id(); $t->integer('store_id'); $t->integer('status')->default(0);
            $t->integer('is_trial')->default(0); $t->integer('validity')->default(30);
            $t->date('expiry_date')->nullable(); $t->timestamps();
        });
        Schema::create('cache', function (Blueprint $t) { $t->string('key')->primary(); $t->text('value'); $t->integer('expiration'); });
        DB::table('vendors')->insert(['id' => 1, 'status' => null]);
        DB::table('stores')->insert(['id' => 1, 'vendor_id' => 1, 'status' => 0]);
    }

    public function test_approval_keeps_model_observers_and_emits_only_one_domain_event(): void
    {
        $updates = 0;
        Event::listen('eloquent.updated: '.Vendor::class, function () use (&$updates) { $updates++; });
        Event::fake([VendorApplicationStatusChanged::class]);
        $service = app(VendorApplicationDecisionService::class);
        $this->assertNotNull($service->decide(1, 1));
        $this->assertNull($service->decide(1, 1));
        $this->assertSame(1, $updates);
        $this->assertSame(1, (int) Store::find(1)->status);
        Event::assertDispatchedTimes(VendorApplicationStatusChanged::class, 1);
        $this->assertFalse(VendorApplicationDecisionService::isDeciding(1));
    }

    public function test_rejection_is_consistent_repeat_safe_and_does_not_activate_subscription(): void
    {
        DB::table('store_subscriptions')->insert(['store_id' => 1, 'status' => 0]);
        Event::fake([VendorApplicationStatusChanged::class]);
        $service = app(VendorApplicationDecisionService::class);
        $service->decide(1, 0, 'Missing documents');
        $this->assertNull($service->decide(1, 0, 'Missing documents'));
        $this->assertSame(0, (int) Vendor::find(1)->status);
        $this->assertSame(0, (int) Store::find(1)->status);
        $this->assertSame(0, (int) DB::table('store_subscriptions')->value('status'));
        Event::assertDispatchedTimes(VendorApplicationStatusChanged::class, 1);
    }

    public function test_missing_rejection_reason_cannot_mutate_state(): void
    {
        try { app(VendorApplicationDecisionService::class)->decide(1, 0, ' '); $this->fail('Expected validation error'); }
        catch (ValidationException) { $this->assertNull(Vendor::find(1)->status); }
    }

    public function test_subscription_activates_on_approval_only(): void
    {
        DB::table('store_subscriptions')->insert(['store_id' => 1, 'status' => 0, 'validity' => 30]);
        app(VendorApplicationDecisionService::class)->decide(1, 1);
        $this->assertSame(1, (int) DB::table('store_subscriptions')->value('status'));
        $this->assertSame('subscription', Store::find(1)->store_business_model);
    }

    public function test_legacy_get_application_route_is_not_a_decision_handler(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes());
        $decision = $routes->first(fn ($r) => $r->getName() === 'admin.store.application');
        $this->assertNotNull($decision);
        $this->assertSame(['POST'], $decision->methods());
        $legacy = $routes->first(fn ($r) => $r->uri() === $decision->uri() && in_array('GET', $r->methods(), true));
        $this->assertNotNull($legacy);
        $this->assertSame('Closure', $legacy->getActionName());
    }

    public function test_decision_route_uses_csrf_and_rejects_a_missing_session_token(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('admin.store.application');
        $this->assertContains(\App\Http\Middleware\VerifyCsrfToken::class,
            $this->app['router']->gatherRouteMiddleware($route));
        $middleware = new class($this->app, $this->app['encrypter']) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        };
        $request = \Illuminate\Http\Request::create(route('admin.store.application', ['id' => 1, 'status' => 0]), 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put('_token', 'fixture-csrf-token');
        try {
            $middleware->handle($request, function () { $this->fail('Missing CSRF token reached the decision'); });
            $this->fail('Missing CSRF token was accepted');
        } catch (\Illuminate\Session\TokenMismatchException) {
            $this->assertNull(Vendor::find(1)->status);
        }
        $request->request->set('_token', 'fixture-csrf-token');
        $response = $middleware->handle($request, function () {
            app(VendorApplicationDecisionService::class)->decide(1, 0, 'Missing documents');
            return response('accepted');
        });
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) Vendor::find(1)->status);
    }
}
