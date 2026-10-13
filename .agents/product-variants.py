from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services')
f=p/'ProductFieldMap.php';s=f.read_text(encoding='utf-8').replace('steps(Store $store): array','steps(Store $store,array $data=[]): array')
s=s.replace("return array_merge($steps,['additional_media','review']);",'''if($type!=='food') {
   $steps[]='attribute_ids';
   foreach($data['attribute_ids']??[] as $id)$steps[]='choice_'.$id;
   foreach($this->combinations($data) as $i=>$label){$steps[]='variant_price_'.$i;$steps[]='variant_stock_'.$i;}
  }
  if(addon_published_status('TaxModule') && \\Modules\\TaxModule\\Entities\\SystemTaxSetup::where('is_active',1)->where('is_default',1)->where('tax_type','product_wise')->exists())$steps[]='tax_ids';
  return array_merge($steps,['additional_media','review']);''')
s=s.replace("['subcategory_id','discount','unit_id','add_ons','additional_media']", "['subcategory_id','discount','unit_id','add_ons','additional_media','attribute_ids','tax_ids']")
s=s.replace(' public function categories(',''' public function combinations(array $data): array {
  if(empty($data['attribute_ids']))return [];$result=[''];
  foreach($data['attribute_ids'] as $id){if(empty($data['choice_'.$id]))return [];$next=[];foreach($result as $base)foreach($data['choice_'.$id] as $v)$next[]=ltrim($base.'-'.str_replace(' ','',$v),'-');$result=$next;if(count($result)>30)throw \\Illuminate\\Validation\\ValidationException::withMessages(['attribute_ids'=>'Use at most 30 variation combinations.']);}
  return $result;
 }
 public function categories(''');f.write_text(s,encoding='utf-8')
f=p/'ProductListingFlow.php';s=f.read_text(encoding='utf-8').replace('$this->fields->steps($d->store)','$this->fields->steps($d->store,$d->data)')
s=s.replace("'additional_media','add_ons'=>[]", "'additional_media','add_ons','attribute_ids','tax_ids'=>[]")
s=s.replace("   if($cmd==='skip')", "   if($cmd==='skip')")
s=s.replace("$media=app(ProductMedia::class)->owned", """$pending=\\Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppMedia::find($m->media_id);
     if($pending && $pending->status==='pending_download')Cache::lock('product-media-'.$pending->id,45)->block(5,fn()=>\\Modules\\WhatsAppVendorConcierge\\app\\Jobs\\ProcessWhatsAppMedia::dispatchSync($pending));
     $media=app(ProductMedia::class)->owned""",1)
s=s.replace("  if(in_array($f,['category_id'",'''  if($f==='attribute_ids'){
   $v=array_values(array_unique(array_map('intval',explode(',',(string)$v))));
   if(count($v)>3||DB::table('attributes')->whereIn('id',$v)->count()!==count($v))throw ValidationException::withMessages([$f=>'Choose up to three existing attribute IDs, or Skip.']);
   foreach(array_keys($data) as $key)if(str_starts_with($key,'choice_')||str_starts_with($key,'variant_'))unset($data[$key]);
  }elseif(str_starts_with($f,'choice_')){
   $v=array_values(array_unique(array_map('trim',explode(',',(string)$v))));if(count($v)>10||count($v)<1)throw ValidationException::withMessages([$f=>'Use 1–10 comma-separated options.']);
   foreach($v as $value)validator(['option'=>$value],['option'=>'required|string|max:40|regex:/^[\\\\pL\\\\pN ]+$/u'])->validate();
   foreach(array_keys($data) as $key)if(str_starts_with($key,'variant_'))unset($data[$key]);
  }elseif(str_starts_with($f,'variant_price_')||str_starts_with($f,'variant_stock_')){
   $v=str_replace([',','₦',' '],'',(string)$v);validator(['value'=>$v],['value'=>str_starts_with($f,'variant_stock_')?'required|integer|min:0':'required|numeric|between:'.\\App\\CentralLogics\\Helpers::getDecimalPlaces().',999999999999.999'])->validate();$v+=0;
  }elseif($f==='tax_ids'){
   $v=array_values(array_unique(array_map('intval',explode(',',(string)$v))));
   $taxes=\\App\\CentralLogics\\Helpers::getTaxSystemType()['taxVats'];if(count(array_intersect($v,$taxes->pluck('id')->all()))!==count($v))throw ValidationException::withMessages([$f=>'Select only the configured tax IDs, or Skip.']);
  }elseif(in_array($f,['category_id' ''',1)
s=s.replace("  $prompt=match($f)",'''  if(str_starts_with($f,'choice_'))return 'List options for '.DB::table('attributes')->where('id',substr($f,7))->value('name').', separated by commas. Example: Black, Blue. Back / Save draft / Cancel.';
  if(preg_match('/^variant_(price|stock)_(\\d+)$/',$f,$a))return 'For variation '.($this->fields->combinations($d->data)[(int)$a[2]]??'').', what is the '.($a[1]==='price'?'actual price in naira?':'stock quantity?').' Back / Save draft / Cancel.';
  $prompt=match($f)''')
s=s.replace("'image'=>'Send a clear", "'attribute_ids'=>'Does it have variants such as size or colour? Choose attribute IDs, separated by commas, or Skip.','tax_ids'=>'Choose existing tax IDs separated by commas, or Skip. Only your admin-configured taxes are available.','image'=>'Send a clear")
s=s.replace("['unit_id','store_category_id','add_ons'],true)", "['unit_id','store_category_id','add_ons','attribute_ids'],true)")
s=s.replace("'unit_id'=>'units','store_category_id'", "'unit_id'=>'units','attribute_ids'=>'attributes','store_category_id'")
s=s.replace("if($f!=='unit_id')$q->where", "if(!in_array($f,['unit_id','attribute_ids'],true))$q->where")
s=s.replace("  $suggest=$d->vision", "  if($f==='tax_ids')$prompt.=\\App\\CentralLogics\\Helpers::getTaxSystemType()['taxVats']->map(fn($t)=>$t->id.': '.$t->name.' ('.$t->tax_rate.'%)')->implode(\"\\n\");\n  $suggest=$d->vision")
s=s.replace("$data['discount_type']='percent';",'''$data['discount_type']='percent';
   $data['attributes']=$data['attribute_ids']??[];$data['choice_options']=[];$data['variations']=[];
   foreach($data['attributes'] as $id)$data['choice_options'][]=['name'=>'choice_'.$id,'title'=>DB::table('attributes')->where('id',$id)->value('name'),'options'=>$data['choice_'.$id]];
   foreach($this->fields->combinations($data) as $i=>$label)$data['variations'][]=['type'=>$label,'price'=>$data['variant_price_'.$i],'stock'=>$data['variant_stock_'.$i]];
   if($data['variations'])$data['stock']=array_sum(array_column($data['variations'],'stock'));''')
f.write_text(s,encoding='utf-8')
