from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text();s=s.replace("            if ($cmd === 'back' || str_starts_with($cmd, 'edit ')) {", "            if ($cmd === 'edit menu') return '✏️ Choose a detail to change. Nothing will be published until you confirm.';\n            if ($cmd === 'back' || str_starts_with($cmd, 'edit ')) {")
s=s.replace("$lines = ['Review your product (nothing created yet):'];", "$lines = ['🛍️ *Review your product*', 'Nothing has been created yet.'];")
s=s.replace("            foreach ($d->data as $k => $v) {", "            foreach ($d->data as $k => $v) {\n                if (in_array($k, ['media_id','image','extra_details'], true)) continue;\n                if ($k === 'additional_media') { $lines[] = '📷 Extra photos: '.count($v); continue; }\n                if ($k === 'unit_id') { $lines[] = 'Selling unit: '.(DB::table('units')->where('id',$v)->value('unit') ?: 'Not specified'); continue; }\n                if ($k === 'attribute_ids') { $lines[] = 'Variants: '.DB::table('attributes')->whereIn('id',$v)->pluck('name')->implode(', '); continue; }\n                if (str_starts_with($k,'choice_')) { $lines[] = (DB::table('attributes')->where('id',substr($k,7))->value('name') ?: 'Options').': '.implode(', ',$v); continue; }")
s=s.replace("$label = $k === 'discount' ? 'Discount (%)' : str_replace('_', ' ', ucfirst($k));", "$label = match($k) { 'category_id'=>'Category', 'subcategory_id'=>'Subcategory', 'discount'=>'Discount (%)', default=>str_replace('_',' ',ucfirst($k)) };")
s=s.replace("$lines[] = $label.': '.$display.' ['.($d->sources[$k] ?? 'vendor').']';", "$lines[] = $label.': '.$display;")
s=s.replace("'additional_media' => 'Send up to five extra product photos, one at a time. Reply Skip when finished.'", "'additional_media' => '📷 *Extra photos (optional)*'.\"\\n\".count($d->data['additional_media'] ?? []).' of 5 saved. Send another photo, or tap *Done with photos* to review your product. You can also type Done or Skip.'")
s=s.replace("Choose a category ID for your shop module:","📂 Choose a category for your product:").replace('Choose a subcategory ID, or Skip:','Choose a subcategory, or skip:').replace('Choose its selling unit ID, or Skip:','Choose its selling unit, or skip:')
p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingPresenter.php');s=p.read_text().replace("        $choices = $this->choices($d);", """        $choices = $this->choices($d);
        if (str_starts_with($reply,'✏️ Choose')) {
            $choices = collect(app(ProductFieldMap::class)->steps($d->store,$d->data))->reject(fn($f)=>$f==='review')->map(fn($f)=>['answer'=>'edit '.$f,'title'=>ucwords(str_replace(['_id','_'],['',' '],$f))])->values()->all();
        }""")
# Edit menu limited to common fields: no confusing paging into normal category list.
s=s.replace("$choices = collect(app(ProductFieldMap::class)->steps($d->store,$d->data))->reject(fn($f)=>$f==='review')->map", "$choices = collect(app(ProductFieldMap::class)->steps($d->store,$d->data))->filter(fn($f)=>in_array($f,['name','image','category_id','description','price','stock','discount','additional_media'],true))->map")
p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationOrchestrator.php');s=p.read_text();needle='        // We only invoke AI orchestration';pos=s.index(needle);s=s[:pos]+'''        if (in_array(mb_strtolower($text), ['thanks','thank you','thank you!','thanks!'],true)) {
            $gateway->sendButtonMessage($contact->phone_number,"You're welcome! 😊 Your progress is saved. What would you like to do next?",[
                ['id'=>$conversation->isOnboarding()?'resume_onboarding':'manage_shop','title'=>$conversation->isOnboarding()?'Continue application':'Manage my shop'],
                ['id'=>'talk_support','title'=>'Talk to Support'],
            ]);
            return true;
        }
        // Ordinary answers belong to the current form, not an AI navigation guess.
        if ($conversation->isOnboarding() || ($conversation->onboarding_session_id && $conversation->current_step)) return false;

'''+s[pos:];p.write_text(s)
