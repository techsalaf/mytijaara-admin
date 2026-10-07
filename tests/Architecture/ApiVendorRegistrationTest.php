<?php

namespace Tests\Architecture;

use App\DTOs\VendorSelfRegistrationInput;
use App\Models\Store;
use App\Models\Translation;
use App\Models\Vendor;
use App\Services\VendorRegistrationNotifier;
use App\Services\VendorSelfRegistrationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiVendorRegistrationTest extends HostWithoutConciergeTest
{
    protected function setUp(): void
    {
        parent::setUp();
        RegistrationSchema::create();
        DB::table('business_settings')->insert([
            ['key' => 'toggle_store_registration', 'value' => '1'],
            ['key' => 'subscription_business_model', 'value' => '1'],
            ['key' => 'commission_business_model', 'value' => '1'],
        ]);
        DB::table('zones')->insert(['id' => 1, 'name' => 'Fixture zone', 'status' => 1]);
        DB::table('modules')->insert(['id' => 1, 'module_name' => 'Fixture module', 'module_type' => 'grocery', 'status' => 1]);
        DB::table('module_zone')->insert(['module_id' => 1, 'zone_id' => 1]);
        DB::table('subscription_packages')->insert(['id' => 7, 'module_type' => 'all', 'status' => 1]);
        $this->app->instance(VendorSelfRegistrationService::class, \Mockery::mock(VendorSelfRegistrationService::class)->makePartial()
            ->shouldAllowMockingProtectedMethods()->shouldReceive('zoneContains')->andReturn(true)->getMock());
        config(['mail.status' => false, 'app.locale' => 'en', 'app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'f_name' => 'Fixture', 'l_name' => 'Applicant', 'email' => 'api@example.test', 'phone' => '+234 (800) 123-4567',
            'password' => 'Fixture-Only!123', 'latitude' => '5', 'longitude' => '5', 'zone_id' => '1', 'module_id' => '1',
            'minimum_delivery_time' => '20', 'maximum_delivery_time' => '40', 'delivery_time_type' => 'min',
            'business_plan' => 'commission', 'package_id' => 'null', 'terms_accepted' => '1', 'privacy_accepted' => '1',
            'translations' => json_encode([
                ['locale' => 'fr', 'key' => 'address', 'value' => 'Adresse'],
                ['locale' => 'en', 'key' => 'address', 'value' => 'API Address'],
                ['locale' => 'fr', 'key' => 'name', 'value' => 'Magasin'],
                ['locale' => 'en', 'key' => 'name', 'value' => 'API Store'],
            ]),
            'logo' => UploadedFile::fake()->image('logo.png'), 'cover_photo' => UploadedFile::fake()->image('cover.png'),
        ], $overrides);
    }

    private function submit(array $overrides = [])
    {
        return $this->post('/api/v1/auth/vendor/register', $this->payload($overrides), ['Accept' => 'application/json']);
    }

    public function test_real_route_keeps_commission_envelope_and_maps_translations_by_key(): void
    {
        $route = collect($this->app['router']->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/auth/vendor/register');
        $this->assertSame('App\\Http\\Controllers\\Api\\V1\\Auth\\VendorLoginController@register', $route->getActionName());
        $response = $this->submit(['delivery_time_type' => 'minute']);
        $response->assertStatus(200);
        $this->assertSame(['store_id', 'type', 'message'], array_keys($response->json()));
        $response->assertJson(['type' => 'commission']);
        $this->assertSame('API Store', Store::firstOrFail()->name);
        $this->assertSame('API Address', Store::firstOrFail()->address);
        $this->assertSame('20-40 min', Store::firstOrFail()->delivery_time);
        $this->assertSame('2348001234567', Vendor::firstOrFail()->phone);
        $this->assertNull(Vendor::firstOrFail()->status);
        $this->assertSame(0, (int) Store::firstOrFail()->status);
        $this->assertDatabaseHas('translations', ['locale' => 'fr', 'key' => 'name', 'value' => 'Magasin']);
        $this->assertTrue(password_verify('Fixture-Only!123', Vendor::firstOrFail()->password));
        $this->assertFalse($this->app['modules']->isEnabled('WhatsAppVendorConcierge'));
    }

    public function test_subscription_is_pending_and_unpaid_with_original_envelope(): void
    {
        $response = $this->submit(['business_plan' => 'subscription', 'package_id' => '7']);
        $response->assertStatus(200)->assertJson(['type' => 'subscription', 'package_id' => 7]);
        $this->assertSame(['store_id', 'package_id', 'type', 'message'], array_keys($response->json()));
        $store = Store::firstOrFail();
        $this->assertSame('none', $store->store_business_model);
        $this->assertSame(0, (int) $store->status);
        $this->assertNull(Vendor::firstOrFail()->status);
    }

    public static function invalidFields(): array
    {
        return [
            'logo' => [['logo' => null], 'logo'], 'cover' => [['cover_photo' => null], 'cover_photo'],
            'surname' => [['l_name' => ''], 'l_name'], 'email' => [['email' => 'invalid'], 'email'],
            'latitude' => [['latitude' => '91'], 'latitude'], 'longitude' => [['longitude' => '-181'], 'longitude'],
            'interval' => [['maximum_delivery_time' => '10'], 'maximum_delivery_time'],
            'unit' => [['delivery_time_type' => 'weeks'], 'delivery_time_type'],
            'zone' => [['zone_id' => '99'], 'zone_id'], 'module' => [['module_id' => '99'], 'module_id'],
            'malformed id' => [['module_id' => '1junk'], 'module_id'],
            'package' => [['business_plan' => 'subscription', 'package_id' => '99'], 'package_id'],
            'plan' => [['business_plan' => 'unknown'], 'business_plan'],
            'terms' => [['terms_accepted' => null], 'terms_accepted'], 'privacy' => [['privacy_accepted' => null], 'privacy_accepted'],
            'translation JSON' => [['translations' => '{'], 'translations'],
            'translation shape' => [['translations' => 'null'], 'translations'],
            'pickup JSON' => [['pickup_zone_id' => 'bad'], 'pickup_zone_id'],
            'default translations missing' => [['translations' => json_encode([
                ['locale' => 'fr', 'key' => 'name', 'value' => 'Magasin'],
                ['locale' => 'fr', 'key' => 'address', 'value' => 'Adresse'],
            ])], 'translations'],
            'translation duplicate key' => [['translations' => json_encode([
                ['locale' => 'en', 'key' => 'name', 'value' => 'First'],
                ['locale' => 'en', 'key' => 'name', 'value' => 'Second'],
                ['locale' => 'en', 'key' => 'address', 'value' => 'Address'],
            ])], 'translations'],
            'database email length' => [['email' => str_repeat('a', 90).'@example.test'], 'email'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_api_input_has_403_error_envelope_and_no_files(array $overrides, string $field): void
    {
        $response = $this->submit($overrides);
        $response->assertStatus(403);
        $this->assertContains($field, array_column($response->json('errors'), 'code'));
        $this->assertSame(['code', 'message'], array_keys($response->json('errors.0')));
        $this->assertSame(0, Vendor::count());
        $this->assertSame(0, Store::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_disabled_registration_replaces_broken_helper_call_with_structured_403(): void
    {
        DB::table('business_settings')->where('key', 'toggle_store_registration')->update(['value' => '0']);
        $this->submit()->assertStatus(403)->assertJsonStructure(['errors']);
        $this->assertSame(0, Vendor::count());
    }

    public function test_duplicate_submission_and_historical_formatted_phone_do_not_overwrite(): void
    {
        $this->submit()->assertStatus(200);
        $this->submit()->assertStatus(403);
        $vendor = Vendor::firstOrFail();
        $vendor->phone = '+234 (800) 123-4567';
        $vendor->save();
        $response = $this->submit(['email' => 'second@example.test']);
        $response->assertStatus(403);
        $this->assertContains('phone', array_column($response->json('errors'), 'code'));
        $this->assertSame(1, Vendor::count());
        $this->assertSame(1, Store::count());
        $this->assertSame('+234 (800) 123-4567', $vendor->fresh()->phone);
    }

    public function test_inactive_or_unrelated_zone_module_and_package_are_rejected(): void
    {
        DB::table('zones')->where('id', 1)->update(['status' => 0]);
        $this->submit()->assertStatus(403);
        DB::table('zones')->where('id', 1)->update(['status' => 1]);
        DB::table('modules')->where('id', 1)->update(['status' => 0]);
        $this->submit()->assertStatus(403);
        DB::table('modules')->where('id', 1)->update(['status' => 1]);
        DB::table('module_zone')->delete();
        $this->submit()->assertStatus(403);
        DB::table('module_zone')->insert(['module_id' => 1, 'zone_id' => 1]);
        DB::table('subscription_packages')->where('id', 7)->update(['module_type' => 'rental']);
        $this->submit(['business_plan' => 'subscription', 'package_id' => '7'])->assertStatus(403);
        $this->assertSame(0, Vendor::count());
    }

    public function test_rental_pickup_json_and_array_are_normalized_and_missing_pickup_is_json_error(): void
    {
        require_once __DIR__.'/fixtures/PublishedRental.php';
        DB::table('modules')->where('id', 1)->update(['module_type' => 'rental']);
        $response = $this->submit();
        $response->assertStatus(403)->assertJsonStructure(['errors']);
        $this->assertContains('pickup_zone_id', array_column($response->json('errors'), 'code'));
        foreach (['["1"]', ['1']] as $index => $pickup) {
            $this->submit(['pickup_zone_id' => $pickup, 'email' => 'rental'.$index.'@example.test', 'phone' => '234800000000'.$index])->assertStatus(200);
            $this->assertSame(['1'], json_decode(Store::latest('id')->firstOrFail()->getRawOriginal('pickup_zone_id'), true));
        }
    }

    public function test_failure_rolls_back_graph_media_and_preserves_unrelated_files(): void
    {
        Storage::disk('public')->put('existing/keep.txt', 'existing');
        Translation::creating(fn () => throw new \RuntimeException('Injected persistence failure'));
        $response = $this->submit();
        $response->assertStatus(500);
        $this->assertSame('registration_unavailable', $response->json('errors.0.code'));
        $this->assertStringNotContainsString('Injected', $response->getContent());
        $this->assertSame(0, Vendor::count());
        $this->assertSame(0, Store::count());
        $this->assertSame(['existing/keep.txt'], Storage::disk('public')->allFiles());
    }

    public function test_outer_rollback_preserves_unrelated_files_and_does_not_notify(): void
    {
        Storage::disk('public')->put('existing/keep.txt', 'existing');
        $this->app->instance(VendorRegistrationNotifier::class, \Mockery::mock(VendorRegistrationNotifier::class)->shouldNotReceive('send')->getMock());
        DB::beginTransaction();
        $this->submit()->assertStatus(200);
        DB::rollBack();
        $this->assertSame(0, Vendor::count());
        $this->assertSame(0, Store::count());
        $this->assertSame(['existing/keep.txt'], Storage::disk('public')->allFiles());
    }

    public function test_notification_failure_after_outer_commit_does_not_change_success_response(): void
    {
        $called = [];
        $notifier = \Mockery::mock(VendorRegistrationNotifier::class);
        $notifier->shouldReceive('send')->once()->andReturnUsing(function () use (&$called) {
            $called[] = DB::transactionLevel();
            throw new \RuntimeException('Injected delivery failure');
        });
        $this->app->instance(VendorRegistrationNotifier::class, $notifier);
        DB::beginTransaction();
        $this->submit()->assertStatus(200);
        $this->assertSame([], $called);
        DB::commit();
        $this->assertSame([0], $called);
        $this->assertSame(1, Vendor::count());
    }

    public static function identityConstraints(): array
    {
        return ['email' => ['email'], 'phone' => ['phone']];
    }

    #[DataProvider('identityConstraints')]
    public function test_real_database_unique_violation_is_safe_403(string $field): void
    {
        // Simulate a competing write after preflight, exercising a real SQLite unique exception.
        Vendor::creating(function ($vendor) use ($field) {
            DB::table('vendors')->insert(['email' => $field === 'email' ? $vendor->email : 'competing@example.test',
                'phone' => $field === 'phone' ? $vendor->phone : '2348000000999']);
        });
        $response = $this->submit();
        $response->assertStatus(403);
        $this->assertSame($field, $response->json('errors.0.code'));
        $this->assertStringNotContainsString('api@example.test', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame(0, Store::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_password_hash_contract_rejects_plaintext_without_domain_writes(): void
    {
        $core = \Mockery::mock(VendorSelfRegistrationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $core->shouldReceive('zoneContains')->andReturn(true);
        $input = new VendorSelfRegistrationInput(firstName: 'Fixture', lastName: 'Applicant', email: 'hash@example.test', phone: '2348000000001',
            names: ['default' => 'Store'], addresses: ['default' => 'Address'], latitude: 5, longitude: 5, zoneId: 1, moduleId: 1,
            minimumDeliveryTime: '20', maximumDeliveryTime: '40', deliveryTimeUnit: 'min', businessPlan: 'commission-base', packageId: null,
            logo: UploadedFile::fake()->image('logo.png'), cover: UploadedFile::fake()->image('cover.png'), passwordHash: 'Plaintext-Only!123', termsAccepted: true, privacyAccepted: true, source: 'api');
        try {
            $core->register($input);
            $this->fail('Plaintext accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('password', $error->errors());
        }
        $this->assertSame(0, Vendor::count());
    }
}
