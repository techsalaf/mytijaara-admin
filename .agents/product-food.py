from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services')
f=p/'ProductFieldMap.php';s=f.read_text(encoding='utf-8');s=s.replace("  if($type!=='food')",'''  if($type==='food'){
   $steps[]='food_group_count';
   for($i=0;$i<($data['food_group_count']??0);$i++){
    foreach(['name','required','min','max','options'] as $part)$steps[]='food_'.$i.'_'.$part;
    foreach($data['food_'.$i.'_options']??[] as $j=>$option)$steps[]='food_'.$i.'_price_'.$j;
   }
  }
  if($type!=='food')''');s=s.replace("'attribute_ids','tax_ids']", "'attribute_ids','tax_ids','food_group_count']");f.write_text(s,encoding='utf-8')
f=p/'ProductListingFlow.php';s=f.read_text(encoding='utf-8').replace("'discount'=>0", "'discount','food_group_count'=>0")
s=s.replace("  if($f==='attribute_ids')",'''  if($f==='food_group_count'){
   validator(['count'=>$v],['count'=>'required|integer|between:0,3'])->validate();$v=(int)$v;
   foreach(array_keys($data) as $key)if(preg_match('/^food_\\d+_/',$key))unset($data[$key]);
  }elseif(preg_match('/^food_(\\d+)_(name|required|min|max|options|price_\\d+)$/',$f,$a)){
   $part=$a[2];
   if($part==='name')validator(['name'=>$v],['name'=>'required|string|max:80'])->validate();
   elseif($part==='required'){$v=match(strtolower((string)$v)){'yes','1'=>'on','no','0'=>'off',default=>null};if($v===null)throw ValidationException::withMessages([$f=>'Reply Yes or No.']);}
   elseif($part==='options'){$v=array_values(array_unique(array_map('trim',explode(',',(string)$v))));validator(['options'=>$v],['options'=>'required|array|min:1|max:10','options.*'=>'required|string|max:80'])->validate();}
   else {validator(['value'=>$v],['value'=>str_starts_with($part,'price_')?'required|numeric|min:0|max:999999999999':'required|integer|between:0,10'])->validate();$v+=0;}
  }elseif($f==='attribute_ids')''')
s=s.replace("  if(str_starts_with($f,'choice_'))",'''  if($f==='food_group_count')return 'How many option groups does this food have (0–3)? Example: size, protein or toppings. Send 0 or Skip for none.';
  if(preg_match('/^food_(\\d+)_(name|required|min|max|options|price_(\\d+))$/',$f,$a))return 'Food option group '.((int)$a[1]+1).': '.match($a[2]){'name'=>'What is the group name?','required'=>'Must a customer choose an option? Yes or No.','min'=>'Minimum number of choices? Use 0 for optional.','max'=>'Maximum number of choices? Use 1 for a single choice.','options'=>'List the option names separated by commas.',default=>'Extra price in naira for '.($d->data['food_'.$a[1].'_options'][(int)($a[3]??0)]??'this option').'? Use 0 for no extra charge.'};
  if(str_starts_with($f,'choice_'))''')
s=s.replace("   $item=app(ProductMutationService::class)",'''   $data['food_variations']=[];
   for($i=0;$i<($data['food_group_count']??0);$i++){
    $prefix='food_'.$i.'_';$min=$data[$prefix.'min'];$max=$data[$prefix.'max'];$required=$data[$prefix.'required'];$options=$data[$prefix.'options'];
    if($max<1||$max<count([])||$min>$max||$max>count($options)||($required==='on'&&$min<1))throw ValidationException::withMessages(['food_'.$i.'_min'=>'Check option group minimum, maximum and required setting.']);
    $values=[];foreach($options as $j=>$label)$values[]=['label'=>$label,'optionPrice'=>$data[$prefix.'price_'.$j]];
    $data['food_variations'][]=['name'=>$data[$prefix.'name'],'type'=>$max===1?'single':'multi','min'=>$min,'max'=>$max,'required'=>$required,'values'=>$values];
   }
   $item=app(ProductMutationService::class)''')
s=s.replace("$max<count([])||",'')
f.write_text(s,encoding='utf-8')
f=p/'CoreAdapters/ProductMutationService.php';s=f.read_text(encoding='utf-8').replace("            'tax_ids' => 'nullable|array',",'''            'food_variations' => 'nullable|array|max:3',
            'food_variations.*.name' => 'required|string|max:80',
            'food_variations.*.type' => 'required|in:single,multi',
            'food_variations.*.min' => 'required|integer|min:0',
            'food_variations.*.max' => 'required|integer|min:1|max:10',
            'food_variations.*.required' => 'required|in:on,off',
            'food_variations.*.values' => 'required|array|min:1|max:10',
            'food_variations.*.values.*.label' => 'required|string|max:80',
            'food_variations.*.values.*.optionPrice' => 'required|numeric|min:0',
            'tax_ids' => 'nullable|array',''')
s=s.replace("        if (!empty($validated['variations']))",'''        foreach($validated['food_variations']??[] as $group){
            if($store->module->module_type!=='food'||$group['min']>$group['max']||$group['max']>count($group['values'])||($group['required']==='on'&&$group['min']<1)||($group['type']==='single'&&$group['max']!==1))throw ValidationException::withMessages(['food_variations'=>['Invalid food option limits.']]);
        }
        if (!empty($validated['variations']))''',1)
s=s.replace("$item->variations = json_encode($validated['variations'] ?? []);", "$item->variations = json_encode($validated['variations'] ?? []);\n            if(array_key_exists('food_variations',$validated))$item->food_variations=json_encode($validated['food_variations']);",1)
f.write_text(s,encoding='utf-8')
