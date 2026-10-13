from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text();idx=s.index('    public function test_extra_photos');s=s[:idx]+'''    public function test_review_is_grouped_and_uses_human_labels_and_naira(): void
    {
        $flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
        $d->update(['step'=>'review','data'=>['name'=>'Earphones','description'=>'Wireless audio','price'=>13000,'stock'=>20,'discount'=>10,'category_id'=>$this->category->id,'media_id'=>29,'additional_media'=>[30,31]]]);
        $reply=$flow->prompt($d);
        foreach(['🛍️ *Review your product*','📦 *Product details*','💰 *Price & stock*','• *Price:* ₦13,000.00',"\\n\\n📝 *Description*",'📷 *Photos*'] as $text)$this->assertStringContainsString($text,$reply);
        $this->assertStringNotContainsString('Media id',$reply);$this->assertStringNotContainsString('[vendor]',$reply);
    }

    public function test_manage_shop_uses_configured_login_path_in_clickable_button(): void
    {
        if(!Schema::hasTable('data_settings'))Schema::create('data_settings',fn(Blueprint $t)=>[$t->id(),$t->string('key'),$t->string('value')]);
        DB::table('data_settings')->insert(['key'=>'store_login_url','value'=>'merchant-access']);
        $gateway=\\Mockery::mock(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\WhatsAppGateway::class);
        $gateway->shouldReceive('sendCtaUrlMessage')->once()->withArgs(function($phone,$body,$label,$url){return $label==='Open dashboard' && $url===url('/login/merchant-access') && str_contains($body,'🏪 *Manage') && !str_contains($body,'seller.mytijaara');})->andReturn([]);
        app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ConversationManager::class)->showManageShop($this->conversation,$this->contact,$gateway);
    }

'''+s[idx:];p.write_text(s)
