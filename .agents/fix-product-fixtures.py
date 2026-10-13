from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge')
f=p/'tests/Hardening/ProductListingFlowTest.php';s=f.read_text(encoding='utf-8').replace("Schema::create('units',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);", "Schema::create('units',fn(Blueprint $t)=>[$t->id(),$t->string('unit')]);Schema::create('system_tax_setups',fn(Blueprint $t)=>[$t->id(),$t->boolean('is_active'),$t->boolean('is_default'),$t->string('tax_type')]);")
s=s.replace("'model_id'=>ProductVisionService::MODEL,'is_enabled'", "'model_id'=>ProductVisionService::MODEL,'name'=>'Vision','is_enabled'")
f.write_text(s,encoding='utf-8')
f=p/'app/Services/ProductListingFlow.php';s=f.read_text(encoding='utf-8').replace("$q->where('id',ctype_digit((string)$v)?(int)$v:-1)->orWhere('name',$v)", "$q->where('id',ctype_digit((string)$v)?(int)$v:-1)->orWhere($f==='unit_id'?'unit':'name',$v)")
s=s.replace("$q->limit(20)->get(['id','name'])->map(fn($r)=>$r->id.': '.$r->name)","$q->limit(20)->get()->map(fn($r)=>$r->id.': '.($r->name??$r->unit))")
s=s.replace("$data[$d->step]=match($d->step)","$data[$d->step]=$d->step==='additional_media'?($data['additional_media']??[]):match($d->step)")
f.write_text(s,encoding='utf-8')
