from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductFieldMap.php');s=p.read_text(encoding='utf-8').replace("if(\\App\\CentralLogics\\Helpers::getTaxSystemType(false)['productWiseTax'])", "if(\\Illuminate\\Support\\Facades\\Schema::hasTable('system_tax_setups') && \\App\\CentralLogics\\Helpers::getTaxSystemType(false)['productWiseTax'])");p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8').replace("     if(!$values)$values[$d->step]=$text;", "     if(preg_match('/(?:^|\\n)\\s*category\\s*:\\s*([^\\n]+)/iu',$text,$a))$values['category_id']=trim($a[1]);\n     if(!$values)$values[$d->step]=$text;")
s=s.replace("$d->errors=$e->errors();$d->stalled_turns++;", "$d->errors=$e->errors();$d->stalled_turns++;if(!array_key_exists($oldStep,$before)&&array_key_exists($oldStep,$d->data))$d->step=$this->next($d);")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/VendorOperationsTest.php');s=p.read_text(encoding='utf-8');start=s.index('        $manager = app(',s.index('function test_photo_to_product_workflow'))
end=s.index("        $this->assertDatabaseHas('items'",start)
s=s[:start]+'''        \\Illuminate\\Support\\Facades\\Schema::create('units',fn($t)=>[$t->id(),$t->string('unit')]);
        \\Illuminate\\Support\\Facades\\Schema::create('attributes',fn($t)=>[$t->id(),$t->string('name')]);
        \\Illuminate\\Support\\Facades\\Schema::table('items',fn($t)=>[$t->text('food_variations')->nullable(),$t->integer('unit_id')->nullable()]);
        $manager = app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ConversationManager::class);
        $gateway = $this->createMock(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\WhatsAppGateway::class);
        $gateway->expects($this->exactly(2))->method('sendTextMessage');
        $manager->handleAiMessage($this->conversation,$this->contact,new \\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMessage(['type'=>'image','media_id'=>$media->id]),$gateway);
        $flow=app(\\Modules\\WhatsAppVendorConcierge\\app\\Services\\ProductListingFlow::class);
        $this->assertSame($media->id,$flow->current($this->conversation)->data['media_id']);
        $manager->handleAiMessage($this->conversation,$this->contact,new \\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMessage(['type'=>'text','raw_text'=>"Name: Yam Tubers (Medium)\\nDescription: Fresh harvest yam\\nPrice: 3500\\nCategory: ".$this->category->name]),$gateway);
        $say=fn($text)=>$flow->receive($this->conversation,$this->contact,new \\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMessage(['type'=>'text','raw_text'=>$text]));
        $say('skip');$say('skip');$say('10');$say('skip');$say('skip');$review=$say('skip');
        $this->assertSame('review',$flow->current($this->conversation)->step);
        $this->assertStringContainsString('Yam Tubers (Medium)',$review);
        $this->assertSame(0,Item::count(),'Answers must only prepare a review');
        \\Illuminate\\Support\\Facades\\Queue::assertNotPushed(\\Modules\\WhatsAppVendorConcierge\\app\\Jobs\\RunVendorAiConversation::class);
        $this->assertStringContainsString('Product #',$say('confirm'));
        $say('confirm');$this->assertSame(1,Item::count());
'''+s[end:];p.write_text(s,encoding='utf-8')
