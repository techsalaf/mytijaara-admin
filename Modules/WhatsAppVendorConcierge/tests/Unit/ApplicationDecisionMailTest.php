<?php
namespace Modules\WhatsAppVendorConcierge\tests\Unit;

use App\Models\Module;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\VendorApplicationDecisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ApplicationDecisionAdapter;
use Modules\WhatsAppVendorConcierge\tests\Hardening\ApplicationFixtureTestCase;

class ApplicationDecisionMailTest extends ApplicationFixtureTestCase
{
    public function test_store_and_rental_decision_mail_preferences_and_repeats(): void
    {
        Schema::create('notification_settings', function ($t) {
            $t->id(); $t->string('type'); $t->string('key'); $t->string('module_type')->nullable(); $t->string('mail_status');
        });
        config(['mail.status' => true]);
        foreach (['approve_mail_status_store', 'deny_mail_status_store', 'rental_approve_mail_status_provider', 'rental_deny_mail_status_provider'] as $key) {
            DB::table('business_settings')->insert(['key' => $key, 'value' => '1']);
            // The host helper memoizes the complete settings collection across
            // test applications. Its supported config override isolates this fixture.
            config([$key.'_conf' => ['value' => '1']]);
        }
        foreach (['grocery', 'rental'] as $type) foreach ([0, 1] as $status) foreach (['active', 'inactive'] as $preference) {
            Mail::fake();
            DB::table('notification_settings')->delete();
            $rental = $type === 'rental';
            $audience = $rental ? 'provider' : 'store';
            DB::table('notification_settings')->insert(['type' => $audience, 'module_type' => $type,
                'key' => $audience.'_registration_'.($status ? 'approval' : 'deny'), 'mail_status' => $preference]);
            $store = new Store();
            $store->setRelation('module', (new Module())->forceFill(['module_type' => $type]));
            $store->setRelation('vendor', (new Vendor())->forceFill(['f_name' => 'Fixture', 'l_name' => 'Owner', 'email' => 'fixture@example.test']));
            $decisions = $this->createMock(VendorApplicationDecisionService::class);
            $decisions->expects($this->exactly(2))->method('decide')->with(1, $status, 'Fixture reason')
                ->willReturnOnConsecutiveCalls($store, null);
            $this->app->instance(VendorApplicationDecisionService::class, $decisions);
            $adapter = app(ApplicationDecisionAdapter::class);
            $this->assertTrue($adapter->decide(1, $status, 'Fixture reason'));
            $this->assertTrue($adapter->decide(1, $status, 'Fixture reason'));
            Mail::assertSentCount($preference === 'active' ? 1 : 0);
            if ($preference === 'active') Mail::assertSent($rental
                ? \Modules\Rental\Emails\ProviderSelfRegistration::class : \App\Mail\VendorSelfRegistration::class);
        }
    }
}
