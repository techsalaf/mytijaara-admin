from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'app/Services/VendorReadinessService.php';s=p.read_text();s=s.replace('        $checks = [',r'''        $subscription = $store->store_sub;
        $planActive = $store->store_business_model === 'commission' || ($subscription
            && (int) $subscription->status === 1
            && ($subscription->max_order === 'unlimited' || (int) $subscription->max_order > 0)
            && $subscription->expiry_date && \Carbon\Carbon::parse($subscription->expiry_date)->endOfDay()->isFuture());
        $coordinates = is_numeric($store->latitude) && is_numeric($store->longitude)
            && abs((float)$store->latitude) <= 90 && abs((float)$store->longitude) <= 180;
        // Reuse the customer's canonical approval/module/category/plan scope.
        $available = $store->items()->active(null, $store->module_id);
        if (config('module.'.$store->module?->module_type.'.stock', true)) $available->where('stock', '>', 0);
        $productAvailable = $available->exists();
        $checks = [''');s=s.replace("$store->store_business_model === 'commission' || (bool) ($store->store_sub?->is_active ?? false)", '$planActive');s=s.replace('Store name, phone, zone, and verified address coordinates', 'Store name, phone, zone, and saved pickup/fulfilment coordinates').replace("!empty($store->address) && !empty($store->zone_id)", "!empty($store->address) && !empty($store->zone_id) && $coordinates");s=s.replace("'title' => 'Store Logo and Cover Photo',\n                'description' => 'Branding assets uploaded for customer discovery',\n                'weight' => 10", "'title' => 'Store Logo and Cover Photo',\n                'description' => 'Branding assets uploaded for customer discovery',\n                'weight' => 5");s=s.replace("'description' => 'At least one item added to the store catalog',\n                'weight' => 15", "'description' => 'At least one item added to the store catalog',\n                'weight' => 10");s=s.replace("'description' => 'At least one product active with available stock',", "'description' => 'An approved product passes the customer visibility rules and applicable stock checks',");s=s.replace("'is_blocking' => false,\n                'completed' => method_exists($store, 'items') ? $store->items()->where('status', 1)->where('stock', '>', 0)->count() >= 1 : false,", "'is_blocking' => true,\n                'completed' => $productAvailable,");s=s.replace("!empty($vendor?->account_no) || !empty($vendor?->bank_name)", "!empty($vendor?->account_no) && !empty($vendor?->bank_name)");s=s.replace("            'payout_setup' => [",r'''            'store_open' => [
                'title' => 'Shop Open', 'description' => 'Merchant availability is enabled',
                'weight' => 5, 'is_blocking' => true, 'completed' => (bool) $store->active,
                'action' => 'Open your shop when you are ready to accept orders.',
            ],
            'fulfilment' => [
                'title' => 'Delivery or Pickup Enabled', 'description' => 'At least one fulfilment option is configured',
                'weight' => 5, 'is_blocking' => true, 'completed' => (bool) $store->delivery || (bool) $store->take_away,
                'action' => 'Enable pickup or configure delivery. Store-managed delivery means your shop arranges its own delivery; platform riders are not automatically assigned.',
            ],
            'payout_setup' => [''');s=s.replace('Your store is 100% ready for customer orders!', 'Your setup checklist is complete. Live opening hours, delivery availability and checkout checks still apply.').replace('Your shop is fully configured and ready to accept customer orders.', 'Your setup checklist is complete. Customer ordering still depends on live hours, fulfilment availability and checkout checks.');p.write_text(s)
p=r/'tests/Hardening/VendorCommandCentreTest.php';s=p.read_text();needle="        Schema::create('store_wallets',";s=s.replace(needle,r'''        Schema::create('modules', function(Blueprint $table) { $table->id(); $table->string('module_name'); $table->string('module_type'); $table->boolean('status')->default(true); });
        Schema::create('categories', function(Blueprint $table) { $table->id(); $table->string('name'); $table->unsignedBigInteger('parent_id')->default(0); $table->boolean('status')->default(true); });
        \Illuminate\Support\Facades\DB::table('modules')->insert(['id'=>1,'module_name'=>'Shop','module_type'=>'ecommerce','status'=>1]);
        \Illuminate\Support\Facades\DB::table('categories')->insert(['id'=>1,'name'=>'Produce','parent_id'=>0,'status'=>1]);
        Schema::create('store_wallets',''',1);s=s.replace("$table->date('expiry_date')->nullable();", "$table->date('expiry_date')->nullable();\n            $table->string('max_order')->default('unlimited');");s=s.replace("$table->boolean('active')->default(true);", "$table->boolean('active')->default(true);\n            $table->boolean('delivery')->default(true);\n            $table->boolean('take_away')->default(false);");s=s.replace("Schema::create('items', function (Blueprint $table) {", "Schema::create('items', function (Blueprint $table) {\n            $table->unsignedBigInteger('category_id')->default(1);\n            $table->tinyInteger('is_approved')->default(1);");s=s.replace("$this->assertEquals(55, $readiness['score']);", "$this->assertEquals(65, $readiness['score']);");s=s.replace("            'name' => 'Fresh Plantains',", "            'name' => 'Fresh Plantains',\n            'module_id' => 1,",1)
idx=s.rfind('\n}');s=s[:idx]+r'''
    public function test_pending_or_rejected_product_never_counts_as_available(): void {
        $item=Item::forceCreate(['name'=>'Pending','store_id'=>$this->store->id,'module_id'=>1,'category_id'=>1,'price'=>100,'stock'=>10,'status'=>1,'is_approved'=>0]);
        $readiness=app(VendorReadinessService::class)->calculateReadiness($this->store);
        $this->assertContains('Product Available for Ordering',$readiness['blocking_items']);
        $item->forceFill(['is_approved'=>1])->save();
        $readiness=app(VendorReadinessService::class)->calculateReadiness($this->store);
        $this->assertNotContains('Product Available for Ordering',$readiness['blocking_items']);
        $this->store->forceFill(['latitude'=>'not-a-coordinate','active'=>false,'delivery'=>false,'take_away'=>false])->save();
        $readiness=app(VendorReadinessService::class)->calculateReadiness($this->store);
        $this->assertContains('Store Profile Completeness',$readiness['blocking_items']);
        $this->assertContains('Shop Open',$readiness['blocking_items']);
        $this->assertContains('Delivery or Pickup Enabled',$readiness['blocking_items']);
    }
'''+s[idx:];p.write_text(s)
