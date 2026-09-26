<?php
namespace Modules\WhatsAppVendorConcierge\tests\Hardening;
use App\Models\Store;
use App\Services\StoreManagedDeliveryPolicy;
use Illuminate\Support\Facades\{DB,Schema,Mail};
use Illuminate\Database\Schema\Blueprint;
use Modules\WhatsAppVendorConcierge\app\Services\{ConversationManager,WhatsAppGateway,VendorAccessNoticeService};
use PHPUnit\Framework\Attributes\Test;
class LaunchOperationsTest extends ApplicationFixtureTestCase
{
    #[Test]
    public function delivery_policy_handles_supported_modules_and_keeps_legacy_default(): void
    {
        Schema::table('stores',fn(Blueprint $t)=>$t->boolean('self_delivery_system')->default(false));
        foreach([1=>'ecommerce',2=>'parcel'] as $id=>$type){DB::table('modules')->insert(['id'=>$id,'module_name'=>$type,'module_type'=>$type]);DB::table('stores')->insert(['id'=>$id,'name'=>$type,'phone'=>'234'.$id,'vendor_id'=>1,'module_id'=>$id]);}
        $store=Store::withoutGlobalScopes()->find(1);
        $this->assertNull(StoreManagedDeliveryPolicy::forStore($store));
        app(StoreManagedDeliveryPolicy::class)->apply(true);
        $this->assertSame(1,(int)DB::table('stores')->where('id',1)->value('self_delivery_system'));
        $this->assertSame(0,(int)DB::table('stores')->where('id',2)->value('self_delivery_system'));
        $store->self_delivery_system=0;$store->name='Updated shop';$store->save();
        $this->assertSame(1,(int)$store->fresh()->self_delivery_system);
        $store->store_business_model='subscription';
        $this->assertSame(1,$store->sub_self_delivery);
        app(StoreManagedDeliveryPolicy::class)->apply(false);
        $this->assertSame(0,$store->sub_self_delivery);
        $this->assertNull(StoreManagedDeliveryPolicy::forStore(Store::withoutGlobalScopes()->find(2)));
    }
    #[Test]
    public function support_link_preserves_the_active_draft_and_does_not_create_takeover(): void
    {
        [$contact,$session,$conversation]=$this->application();
        $gateway=$this->createMock(WhatsAppGateway::class);
        $gateway->expects($this->once())->method('sendCtaUrlMessage')->with($contact->phone_number,$this->stringContains('keep using'),'Talk to Support',$this->stringContains('https://wa.me/2347049147825?text='))->willReturn(['messages'=>[['id'=>'wamid.support']]]);
        app(ConversationManager::class)->initiateHumanHandoff($conversation,$contact,$gateway);
        $this->assertSame('onboarding_active',$conversation->fresh()->state);
        $this->assertSame('account_password',$conversation->fresh()->current_step);
        $this->assertSame(0,DB::table('whatsapp_support_cases')->count());
    }
    #[Test]
    public function account_email_is_escaped_and_contains_no_password_or_religious_greeting(): void
    {
        $d=['name'=>'Shop <script>','id'=>1,'module'=>'Shop','email'=>'owner@example.test','delivery'=>'You arrange delivery','login_url'=>'https://example.test/login/vendor','support_url'=>'https://wa.me/2347049147825'];
        $html=(new \Modules\WhatsAppVendorConcierge\app\Mail\VendorAccessMail($d))->render();
        $this->assertStringContainsString('Shop &lt;script&gt;',$html);
        $this->assertStringContainsString('Add to Home Screen',$html);
        $this->assertStringNotContainsString('Assalaamu',$html);
    }
    #[Test]
    public function notices_are_audited_and_email_is_not_resent_or_sent_in_preview(): void
    {
        Schema::table('stores',fn(Blueprint $t)=>$t->boolean('active')->default(true));
        Schema::create('data_settings',function(Blueprint $t){$t->id();$t->string('key');$t->text('value');});
        DB::table('modules')->insert(['id'=>1,'module_name'=>'Shop','module_type'=>'ecommerce']);
        DB::table('vendors')->insert(['id'=>1,'email'=>'owner@example.test','phone'=>'2348000000000','status'=>1]);
        DB::table('stores')->insert(['id'=>1,'name'=>'Shop','phone'=>'2348000000000','vendor_id'=>1,'module_id'=>1,'status'=>1]);
        $store=Store::withoutGlobalScopes()->find(1);$service=app(VendorAccessNoticeService::class);
        $this->assertSame('dry_run_passed',$service->send($store,'email',true)['status']);Mail::assertNothingSent();
        $this->assertSame('success',$service->send($store,'email',false)['status']);
        $this->assertSame('excluded',$service->send($store,'email',false)['status']);
        Mail::assertSent(\Modules\WhatsAppVendorConcierge\app\Mail\VendorAccessMail::class,1);
        $this->assertSame('excluded',$service->send($store,'whatsapp',false)['status']);
    }
    #[Test]
    public function cleanup_preserves_unfinished_releases_and_never_follows_links(): void
    {
        require_once base_path('scripts/prune-releases.php');
        $root=base_path('.agents/prune-test-'.bin2hex(random_bytes(5)));mkdir($root.'/app/storage/framework',0775,true);file_put_contents($root.'/app/artisan','');
        $app=realpath($root.'/app');$parent=dirname($app).'/.mytijaara-releases';
        foreach(['100-1','200-1'] as $id){$p=$parent.'/'.$id;mkdir($p.'/incoming',0775,true);mkdir($p.'/journal',0775,true);file_put_contents($p.'/incoming/code.php','test');file_put_contents($p.'/journal/release.json',json_encode(['target'=>$app,'incoming'=>$p.'/incoming']));}
        file_put_contents($parent.'/100-1/completed','');
        file_put_contents($app.'/keep.txt','runtime');
        @symlink($app.'/keep.txt',$parent.'/100-1/incoming/runtime-link');
        $this->assertCount(1,pruneReleases($app)['actions']);$this->assertFileExists($parent.'/100-1/incoming/code.php');
        pruneReleases($app,true);$this->assertFileExists($app.'/keep.txt');$this->assertDirectoryDoesNotExist($parent.'/100-1/incoming');$this->assertFileExists($parent.'/100-1/journal/release.json');$this->assertFileExists($parent.'/200-1/incoming/code.php');
        // Only our uniquely generated, resolved fixture root is eligible for removal.
        $this->assertStringStartsWith(realpath(base_path('.agents')),realpath($root));removeReleaseTree($root);
    }
}
