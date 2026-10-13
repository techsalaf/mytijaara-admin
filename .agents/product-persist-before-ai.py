from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text(encoding='utf-8');needle="                        $d->vision = in_array($d->vision['status'] ?? '', ['disabled_by_vendor', 'disabled_by_admin'], true) ? $d->vision : app(ProductVisionService::class)->analyse($media, $d->store);";s=s.replace(needle,"""                        // Save the owned image and next step before the external request.
                        $disabled=in_array($d->vision['status']??'', ['disabled_by_vendor','disabled_by_admin'], true);
                        $d->data=$data;$d->sources=$sources;$d->step=$this->next($d);
                        if(!$disabled)$d->vision=['status'=>'processing'];
                        $d->save();
                        if(!$disabled)$d->vision=app(ProductVisionService::class)->analyse($media,$d->store);""");p.write_text(s,encoding='utf-8')
