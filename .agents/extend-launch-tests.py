from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/LaunchOperationsTest.php');s=p.read_text();s=s.replace("        $store->store_business_model='subscription';", "        $store->self_delivery_system=0;$store->name='Updated shop';$store->save();\n        $this->assertSame(1,(int)$store->fresh()->self_delivery_system);\n        $store->store_business_model='subscription';");pos=s.index('    #[Test]\n    public function cleanup');s=s[:pos]+r'''    #[Test]
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
''' +s[pos:];p.write_text(s)
