from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/ProductMutationService.php');s=p.read_text(encoding='utf-8')
s=s.replace("            'additional_media.*' => 'integer|min:1',",'''            'additional_media.*' => 'integer|min:1',
            'attributes' => 'nullable|array|max:3',
            'attributes.*' => 'integer|exists:attributes,id',
            'choice_options' => 'nullable|array|max:3',
            'choice_options.*.name' => 'required|string',
            'choice_options.*.title' => 'required|string',
            'choice_options.*.options' => 'required|array|min:1|max:10',
            'variations' => 'nullable|array|max:30',
            'variations.*.type' => 'required|string|max:191',
            'variations.*.price' => 'required|numeric|between:'.Helpers::getDecimalPlaces().',999999999999.999',
            'variations.*.stock' => 'required|integer|min:0',
            'tax_ids' => 'nullable|array',
            'tax_ids.*' => 'integer',
''')
s=s.replace("        // Validate category belongs to store module",'''        if (!empty($validated['variations'])) {
            if ($store->module->module_type === 'food') throw ValidationException::withMessages(['variations'=>['Food uses option groups rather than inventory variations.']]);
            $parts=[''];
            if(count($validated['attributes']??[])!==count($validated['choice_options']??[])) throw ValidationException::withMessages(['variations'=>['Attribute choices are incomplete.']]);
            foreach($validated['choice_options']??[] as $i=>$choice){
                $id=$validated['attributes'][$i];
                if($choice['name']!=='choice_'.$id || $choice['title']!==DB::table('attributes')->where('id',$id)->value('name'))throw ValidationException::withMessages(['variations'=>['Invalid attribute mapping.']]);
                $next=[];foreach($parts as $base)foreach($choice['options'] as $option)$next[]=ltrim($base.'-'.str_replace(' ','',$option),'-');$parts=$next;
            }
            if(count($parts)!==count($validated['variations']) || $parts!==array_column($validated['variations'],'type')) throw ValidationException::withMessages(['variations'=>['Variation combinations do not match the choices.']]);
            foreach($validated['variations'] as $variation)$this->validateDiscount((float)$variation['price'],(float)($validated['discount']??0),$validated['discount_type']??'percent');
            $validated['stock']=array_sum(array_column($validated['variations'],'stock'));
        }
        if (!empty($validated['tax_ids'])) {
            $taxes=Helpers::getTaxSystemType()['taxVats'];
            if(count(array_intersect($validated['tax_ids'],$taxes->pluck('id')->all()))!==count($validated['tax_ids'])) throw ValidationException::withMessages(['tax_ids'=>['Select only configured taxes.']]);
        }

        // Validate category belongs to store module''',1)
s=s.replace("$item->variations = json_encode([]);", "$item->variations = json_encode($validated['variations'] ?? []);",1).replace("$item->attributes = json_encode([]);", "$item->attributes = json_encode($validated['attributes'] ?? []);",1).replace("$item->choice_options = json_encode([]);", "$item->choice_options = json_encode($validated['choice_options'] ?? []);",1)
s=s.replace("            // Save default translation",'''            if (!empty($validated['tax_ids']) && addon_published_status('TaxModule')) {
                $setup=\\Modules\\TaxModule\\Entities\\SystemTaxSetup::where('is_active',1)->where('is_default',1)->where('tax_type','product_wise')->first();
                if($setup)foreach($validated['tax_ids'] as $taxId)\\Modules\\TaxModule\\Entities\\Taxable::create(['taxable_type'=>Item::class,'taxable_id'=>$item->id,'system_tax_setup_id'=>$setup->id,'tax_id'=>$taxId]);
            }
            // Save default translation''',1)
p.write_text(s,encoding='utf-8')
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationManager.php');s=p.read_text(encoding='utf-8');a=s.index('\n    {',s.index('public function handleListResponse'))+6;s=s[:a]+'''
        if (!in_array($selectionId,['add_products','talk_support'],true)) {
            app(ProductListingFlow::class)->current($conversation)?->update(['status'=>'saved']);
        }
'''+s[a:];p.write_text(s,encoding='utf-8')
