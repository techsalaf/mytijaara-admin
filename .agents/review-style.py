from pathlib import Path
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingFlow.php');s=p.read_text();a=s.index("        if ($f === 'review') {",s.index('    public function prompt'));b=s.index("        if ($f === 'extra_details')",a);s=s[:a]+"        if ($f === 'review') return app(ProductListingPresenter::class)->review($d);\n"+s[b:];p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ProductListingPresenter.php');s=p.read_text();pos=s.index('    public function choices(');s=s[:pos]+'''    public function review(Draft $d): string
    {
        $data=$d->data;
        $money=fn($amount)=>'₦'.number_format((float)$amount,2);
        $category=fn($id)=>app(ProductFieldMap::class)->categories($d->store)->whereKey($id)->value('name') ?: 'Not specified';
        $sections=["🛍️ *Review your product*\\nNothing has been created yet.",
            "📦 *Product details*\\n• *Name:* ".($data['name']??'')."\\n• *Category:* ".$category($data['category_id']??null).(!empty($data['subcategory_id'])?" → ".$category($data['subcategory_id']):'')."\\n\\n📝 *Description*\\n".($data['description']??'')];
        $pricing=["💰 *Price & stock*",'• *Price:* '.$money($data['price']??0),'• *Discount:* '.($data['discount']??0).'%'];
        if(array_key_exists('stock',$data))$pricing[]='• *Stock:* '.$data['stock'].' units';
        if(!empty($data['unit_id']))$pricing[]='• *Selling unit:* '.DB::table('units')->where('id',$data['unit_id'])->value('unit');
        $sections[]=implode("\\n",$pricing);
        $variants=app(ProductFieldMap::class)->combinations($data);
        if($variants){$lines=['🎨 *Variants*'];foreach($variants as $i=>$label)$lines[]='• *'.$label.'*: '.$money($data['variant_price_'.$i]??0).' · '.($data['variant_stock_'.$i]??0).' in stock';$sections[]=implode("\\n",$lines);}
        for($i=0;$i<($data['food_group_count']??0);$i++){
            $lines=['🍽️ *'.($data['food_'.$i.'_name']??'Options').'*','• '.(($data['food_'.$i.'_required']??'off')==='on'?'Required':'Optional').' · Choose '.($data['food_'.$i.'_min']??0).'–'.($data['food_'.$i.'_max']??0)];
            foreach($data['food_'.$i.'_options']??[] as $j=>$option)$lines[]='• '.$option.': +'.$money($data['food_'.$i.'_price_'.$j]??0);
            $sections[]=implode("\\n",$lines);
        }
        $extra=[];
        $skip=['name','description','category_id','subcategory_id','price','discount','stock','unit_id','media_id','image','extra_details','additional_media','attribute_ids','food_group_count'];
        foreach($data as $key=>$value){
            if(in_array($key,$skip,true)||preg_match('/^(choice_|variant_|food_\\d+_)/',$key)||$value===null||$value===[])continue;
            $label=ucwords(str_replace('_',' ',preg_replace('/_ids?$/','',$key)));
            if(in_array($key,['veg','is_prescription_required','organic','basic'],true)){$label=['veg'=>'Vegetarian','is_prescription_required'=>'Prescription required','organic'=>'Organic','basic'=>'Basic pharmacy item'][$key];$value=$value?'Yes':'No';}
            $table=['store_category_id'=>'store_categories','brand_id'=>'brands','condition_id'=>'common_conditions','add_ons'=>'add_ons','tax_ids'=>'taxes'][$key]??null;
            if($table){$ids=is_array($value)?$value:[$value];$value=DB::table($table)->whereIn('id',$ids)->pluck('name')->all();}
            $extra[]='• *'.$label.':* '.(is_array($value)?implode(', ',$value):$value);
        }
        if($extra)$sections[]="📋 *Additional details*\\n".implode("\\n",$extra);
        $sections[]='📷 *Photos*'."\\n• Main photo: ".(!empty($data['media_id'])?'Saved ✅':'Not added')."\\n• Extra photos: ".count($data['additional_media']??[]);
        $sections[]="✅ *Ready to submit?*\\nTap *Create product* to confirm, *Edit details* to make changes, or *Save for later*.\\n\\n_Your product follows the store’s admin approval settings._";
        return implode("\\n\\n",$sections);
    }

'''+s[pos:]
s=s.replace("        $choices = $this->choices($d);", "        $reply = WhatsAppCopy::format($reply);\n        $reply = str_replace(['Back • Save draft • Cancel • Support','Back / Save draft / Cancel.'], 'Tap an option below. Type *Cancel* to discard or *Support* for help.', $reply);\n        $choices = $this->choices($d);")
s=s.replace("$buttons[]=['answer'=>'save draft','title'=>'💾 Save for later'];\n        }", "$buttons[]=['answer'=>'save draft','title'=>'💾 Save for later'];\n            if(count($buttons)<3)$buttons[]=['answer'=>'cancel','title'=>'✖ Cancel product'];\n        }")
p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/WhatsAppGateway.php');s=p.read_text();sig='public function sendCtaUrlMessage(string $to, string $body, string $buttonText, string $url, ?string $header = null, ?string $footer = null): array';s=s.replace(sig+'\n    {',sig+'\n    {\n        $body = WhatsAppCopy::format($body);')
# Keep newly formatted bodies inside Meta limits without dropping details.
for name in ['sendButtonMessage','sendListMessage','sendCtaUrlMessage']:
 a=s.index('public function '+name);b=s.index('$body = WhatsAppCopy::format($body);',a)+len('$body = WhatsAppCopy::format($body);');s=s[:b]+"\n        if (mb_strlen($body)>1000) { $this->sendTextMessage($to,$body); $body='👇 Choose your next step below.'; }"+s[b:]
a=s.index('public function sendTextMessage');b=s.index('$body = WhatsAppCopy::format($body);',a)+len('$body = WhatsAppCopy::format($body);');s=s[:b]+"\n        if (mb_strlen($body)>4000) { $result=[]; for($i=0;$i<mb_strlen($body);$i+=3500)$result=$this->sendTextMessage($to,mb_substr($body,$i,3500),$previewUrl); return $result; }"+s[b:];p.write_text(s)
