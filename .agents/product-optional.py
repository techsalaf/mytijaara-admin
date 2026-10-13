from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services')
f=p/'ProductFieldMap.php';s=f.read_text(encoding='utf-8').replace("if(addon_published_status('TaxModule') && \\Modules\\TaxModule\\Entities\\SystemTaxSetup::where('is_active',1)->where('is_default',1)->where('tax_type','product_wise')->exists())", "if(\\App\\CentralLogics\\Helpers::getTaxSystemType(false)['productWiseTax'])")
s=s.replace("  return array_merge($steps,['additional_media','review']);",'''  $steps[]='extra_details';
  if(!empty($data['extra_details'])){
   $steps[]='tags';
   foreach(['brand'=>'brand_id','organic'=>'organic','nutrition'=>'nutritions','allergy'=>'allergies','common_condition'=>'condition_id','generic_name'=>'generic_name','basic'=>'basic'] as $cap=>$field)if(config("module.$type.$cap"))$steps[]=$field;
   if($type==='pharmacy')$steps=array_merge($steps,['manufacturer','unit_value']);
  }
  return array_merge($steps,['additional_media','review']);''')
s=s.replace("'food_group_count']", "'food_group_count','extra_details','tags','brand_id','organic','nutritions','allergies','condition_id','generic_name','basic','manufacturer','unit_value']")
f.write_text(s,encoding='utf-8')
f=p/'ProductListingFlow.php';s=f.read_text(encoding='utf-8').replace("'discount','food_group_count'=>0", "'discount','food_group_count','extra_details','organic','basic'=>0").replace("'attribute_ids','tax_ids'=>[]", "'attribute_ids','tax_ids','tags','nutritions','allergies'=>[]")
s=s.replace("  if($f==='food_group_count')",'''  if(in_array($f,['tags','nutritions','allergies'],true)){
   $v=array_values(array_unique(array_map('trim',explode(',',(string)$v))));validator(['values'=>$v],['values'=>'array|max:15','values.*'=>'required|string|max:100'])->validate();
  }elseif(in_array($f,['extra_details','organic','basic'],true)){
   $v=match(strtolower((string)$v)){'yes','1'=>1,'no','0'=>0,default=>null};if($v===null)throw ValidationException::withMessages([$f=>'Reply Yes or No, or Skip.']);
  }elseif(in_array($f,['manufacturer','unit_value','generic_name'],true)){
   validator(['value'=>$v],['value'=>'required|string|max:191'])->validate();
  }elseif(in_array($f,['brand_id','condition_id'],true)){
   $table=$f==='brand_id'?'brands':'common_conditions';$row=DB::table($table)->where('id',ctype_digit((string)$v)?(int)$v:-1)->first();if(!$row)throw ValidationException::withMessages([$f=>'Choose an existing ID, or Skip.']);$v=$row->id;
  }elseif($f==='food_group_count')''',1)
s=s.replace("  if($f==='food_group_count')return",'''  if($f==='extra_details')return 'Would you like to add optional details such as brand, tags or module-specific information? Yes or Skip.';
  if(in_array($f,['brand_id','condition_id'],true))return 'Choose an existing '.($f==='brand_id'?'brand':'condition').' ID, or Skip: '.DB::table($f==='brand_id'?'brands':'common_conditions')->limit(20)->get()->map(fn($r)=>$r->id.': '.$r->name)->implode(', ');
  if(in_array($f,['organic','basic'],true))return ($f==='organic'?'Is this product organic?':'Is this a basic pharmacy item?').' Only confirm verified information. Yes / No / Skip.';
  if(in_array($f,['tags','nutritions','allergies','manufacturer','unit_value','generic_name'],true))return 'Provide '.str_replace('_',' ',$f).(in_array($f,['tags','nutritions','allergies'],true)?', separated by commas':'').'. Use only information you can verify, or Skip.';
  if($f==='food_group_count')return''')
f.write_text(s,encoding='utf-8')
f=p/'CoreAdapters/ProductMutationService.php';s=f.read_text(encoding='utf-8').replace("            'tax_ids' => 'nullable|array',",'''            'brand_id' => 'nullable|integer|exists:brands,id',
            'condition_id' => 'nullable|integer|exists:common_conditions,id',
            'organic' => 'nullable|boolean',
            'basic' => 'nullable|boolean',
            'manufacturer' => 'nullable|string|max:191',
            'unit_value' => 'nullable|string|max:191',
            'generic_name' => 'nullable|string|max:191',
            'tags' => 'nullable|array|max:15',
            'tags.*' => 'required|string|max:100',
            'nutritions' => 'nullable|array|max:15',
            'nutritions.*' => 'required|string|max:100',
            'allergies' => 'nullable|array|max:15',
            'allergies.*' => 'required|string|max:100',
            'tax_ids' => 'nullable|array',''')
s=s.replace("$details->brand_id = null;", "$details->brand_id = $validated['brand_id'] ?? null;",1).replace("$details->is_basic = 0;", "$details->is_basic = $validated['basic'] ?? 0;\n                $details->common_condition_id=$validated['condition_id']??null;\n                $details->manufacturer=$validated['manufacturer']??null;\n                $details->unit_value=$validated['unit_value']??null;",1)
s=s.replace("            $item->status = 1;", "            if(array_key_exists('organic',$validated) && $store->module->module_type==='grocery')$item->organic=$validated['organic'];\n            $item->status = 1;",1)
s=s.replace("            // Save default translation",'''            foreach(['tags'=>[\\App\\Models\\Tag::class,'tag'],'nutritions'=>[\\App\\Models\\Nutrition::class,'nutrition'],'allergies'=>[\\App\\Models\\Allergy::class,'allergy']] as $relation=>[$class,$column]){
                if(empty($validated[$relation]))continue;
                if($relation!=='tags' && !in_array($store->module->module_type,['food','grocery'],true))continue;
                $ids=[];foreach($validated[$relation] as $value)$ids[]=$class::firstOrCreate([$column=>$value])->id;$item->$relation()->sync($ids);
            }
            if(!empty($validated['generic_name']) && $store->module->module_type==='pharmacy')$item->generic()->sync([\\App\\Models\\GenericName::firstOrCreate(['generic_name'=>$validated['generic_name']])->id]);
            // Save default translation''',1)
f.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php');s=p.read_text(encoding='utf-8').replace("$t->boolean('is_default'),$t->string('tax_type')", "$t->boolean('is_default'),$t->string('tax_payer')->default('vendor'),$t->boolean('is_included')->default(0),$t->string('tax_type')")
s=s.replace("$this->say('skip');$this->say('skip');$this->say('skip');$this->say('skip');$this->assertSame('review'", "$this->say('skip');$this->say('skip');$this->say('skip');$this->say('skip');$this->say('skip');$this->assertSame('review'")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/tests/Integration/MySqlProductListingTest.php');s=p.read_text(encoding='utf-8').replace("'email'=>'fixture@example.test','status'=>1", "'email'=>'fixture@example.test','password'=>bcrypt('Fixture-only-123!'),'status'=>1").replace("$say('21:00');$say('skip');$say('skip');$say('skip');", "$say('21:00');$say('skip');$say('skip');$say('skip');$say('skip');");p.write_text(s,encoding='utf-8')
