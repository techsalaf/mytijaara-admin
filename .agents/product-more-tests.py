from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text(encoding='utf-8');idx=s.rfind('\n}')
s=s[:idx]+'''
 public function test_real_message_id_is_consumed_once_and_unowned_draft_is_rejected(): void {
  $this->say('add product');$this->say('edit name');
  $message=WhatsAppMessage::create(['conversation_id'=>$this->conversation->id,'whatsapp_message_id'=>'unique-retry','direction'=>'inbound','type'=>'text','raw_text'=>'Blue Bag','content'=>['text'=>'Blue Bag']]);
  $flow=app(ProductListingFlow::class);$flow->receive($this->conversation,$this->contact,$message);$before=$this->draft()->data;
  $this->assertSame('',$flow->receive($this->conversation,$this->contact,$message));$this->assertSame($before,$this->draft()->data);
  $other=clone $this->contact;$other->id=999;
  try{$flow->receive($this->conversation,$other,$message);$this->fail('Unowned draft accepted');}catch(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
 }
 public function test_taxonomy_conflict_rolls_back_all_new_records(): void {
  foreach([1,2] as $i)\\App\\Models\\Category::forceCreate(['name'=>'Fresh Food','module_id'=>$this->module->id,'parent_id'=>0,'position'=>0,'status'=>1]);
  $count=DB::table('categories')->count();
  try{app(LaunchTaxonomyService::class)->run(true);$this->fail('Conflict was applied');}catch(\\RuntimeException $e){$this->assertStringContainsString('conflict',$e->getMessage());}
  $this->assertSame($count,DB::table('categories')->count());$this->assertSame(0,DB::table('whatsapp_taxonomy_keys')->count());
 }
 public function test_variants_require_each_price_and_stock_and_preserve_host_shape(): void {
  DB::table('attributes')->insert(['id'=>1,'name'=>'Colour']);$flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
  foreach(['attribute_ids'=>'1','choice_1'=>'Black, Blue','variant_price_0'=>'1000','variant_stock_0'=>'2','variant_price_1'=>'1100','variant_stock_1'=>'3'] as $field=>$value)$flow->answer($d,$field,$value);
  $this->assertSame(['Black','Blue'],app(ProductFieldMap::class)->combinations($d->data));
  $d->data=array_merge($d->data,$this->productData(['name'=>'Bag','price'=>1000,'category_id'=>$this->category->id]));$d->step='review';$d->save();$flow->create($d,$this->contact);
  $item=DB::table('items')->first();$this->assertSame(5,$item->stock);$this->assertSame([['type'=>'Black','price'=>1000,'stock'=>2],['type'=>'Blue','price'=>1100,'stock'=>3]],json_decode($item->variations,true));
 }
 public function test_food_options_reject_impossible_limits_before_creating(): void {
  $this->module->update(['module_type'=>'food']);$flow=app(ProductListingFlow::class);$d=$flow->start($this->conversation,$this->contact);
  $d->data=['name'=>'Rice','description'=>'Rice meal','category_id'=>$this->category->id,'price'=>1000,'food_group_count'=>1,'food_0_name'=>'Protein','food_0_required'=>'on','food_0_min'=>3,'food_0_max'=>1,'food_0_options'=>['Egg'],'food_0_price_0'=>100];$d->step='review';$d->save();
  try{$flow->create($d,$this->contact);$this->fail('Invalid food group created');}catch(\\Illuminate\\Validation\\ValidationException){$this->assertSame(0,DB::table('items')->count());}
 }
''' + s[idx:];p.write_text(s,encoding='utf-8')
