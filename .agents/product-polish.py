from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8')
s=s.replace("   $d->save();\n   if($d->needs_attention)",'''   $variants=$this->fields->combinations($d->data);
   if($variants && count(array_filter(array_keys($d->data),fn($key)=>str_starts_with($key,'variant_stock_')))===count($variants)){
    $values=$d->data;$values['stock']=array_sum(array_map(fn($i)=>$values['variant_stock_'.$i],array_keys($variants)));$d->data=$values;
    $sources=$d->sources;$sources['stock']='sum of vendor variation quantities';$d->sources=$sources;
   }
   $d->save();
   if($d->needs_attention)''')
s=s.replace("if($rows->count()!==1)throw ValidationException::withMessages([$f=>'No exact matching option. Choose a listed ID or search using Options followed by a word.']);", "if($rows->count()!==1)throw ValidationException::withMessages([$f=>'No exact matching option. Choose a listed ID or search using Options followed by a word.'.(in_array($f,['category_id','subcategory_id'],true)?\"\\nPossible options (please choose an ID):\\n\".$this->options($d,(string)$v):'')]);")
s=s.replace("  if($search!=='')$q->where", "  $aliases=['body cream'=>'skin care','cream'=>'skin care','ear pod'=>'audio','earphone'=>'audio','headphone'=>'audio','clothing'=>'fashion','necklace'=>'accessories'];\n  foreach($aliases as $term=>$mapped)if(str_contains(mb_strtolower($search),$term)){$search=$mapped;break;}\n  if($search!=='')$q->where")
s=s.replace("$lines[]=str_replace('_',' ',ucfirst($k)).': '.$display", "if(preg_match('/^variant_(price|stock)_(\\d+)$/',$k,$a))$label=($this->fields->combinations($d->data)[(int)$a[2]]??'Variation').' '.$a[1];else $label=$k==='discount'?'Discount (%)':str_replace('_',' ',ucfirst($k));$lines[]=$label.': '.$display")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Http/Controllers/Admin/ProductDraftController.php');s=p.read_text(encoding='utf-8').replace("$messages=$draft->conversation->messages()->latest('id')->limit(2)->get();", "$messages=collect(['inbound','outbound'])->map(fn($direction)=>$draft->conversation->messages()->where('direction',$direction)->latest('id')->first())->filter();");p.write_text(s,encoding='utf-8')
