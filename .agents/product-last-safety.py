from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8').replace("   if($cmd==='confirm' || $cmd==='confirm and create'){", "   if(($cmd==='confirm' || $cmd==='confirm and create') && $d->status==='saved')return 'Your draft is saved. Reply Resume product, review it, then confirm.';\n   if($cmd==='confirm' || $cmd==='confirm and create'){")
s=s.replace("$taxes->pluck('id')->all()", "collect($taxes)->pluck('id')->all()")
s=s.replace("$data[$f]=$v;$sources[$f]=", "$data[$f]=$v;\n  if(str_starts_with($f,'choice_'))$this->fields->combinations($data);\n  $sources[$f]=")
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/ProductMutationService.php');s=p.read_text(encoding='utf-8').replace("$taxes->pluck('id')->all()", "collect($taxes)->pluck('id')->all()");p.write_text(s,encoding='utf-8')
