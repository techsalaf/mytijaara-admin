<?php
namespace Modules\WhatsAppVendorConcierge\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\WhatsAppVendorConcierge\app\Models\{ProductListingDraft as Draft, WhatsAppMessage};

/** Human-facing controls; IDs are scoped to an owned draft and its current question. */
class ProductListingPresenter
{
    public static function selection(WhatsAppMessage $message): ?string
    {
        $id = $message->content['interactive']['button_reply']['id'] ?? $message->content['interactive']['list_reply']['id'] ?? null;
        return is_string($id) && str_starts_with($id, 'pd:') ? $id : null;
    }

    public static function id(Draft $d, string $answer): string
    {
        return 'pd:'.$d->id.':'.substr(hash('sha256', $d->step.'|'.json_encode($d->data).'|'.(string) ($d->sources['_cancel_requested'] ?? 'edit')), 0, 10).':'.rawurlencode($answer);
    }

    public static function decode(Draft $d, string $id): ?string
    {
        $prefix = self::id($d, '');
        return str_starts_with($id, $prefix) ? rawurldecode(substr($id, strlen($prefix))) : null;
    }

    public function send(WhatsAppGateway $gateway, string $phone, string $reply, Draft $d, int $page = 0): void
    {
        $reply = WhatsAppCopy::format($reply);
        $reply = str_replace(['Back • Save draft • Cancel • Support','Back / Save draft / Cancel.'], 'Tap an option below. Type *Cancel* to discard or *Support* for help.', $reply);
        $choices = $this->choices($d);
        if (str_starts_with($reply,'✏️ Choose')) {
            $choices = collect(app(ProductFieldMap::class)->steps($d->store,$d->data))->filter(fn($f)=>in_array($f,['name','image','category_id','description','price','stock','discount','additional_media'],true))->map(fn($f)=>['answer'=>'edit '.$f,'title'=>ucwords(str_replace(['_id','_'],['',' '],$f))])->values()->all();
        }
        $buttons = [];
        if (!empty($d->sources['_cancel_requested'])) {
            $buttons = [['answer'=>'confirm cancel','title'=>'✖ Confirm cancel'],['answer'=>'keep editing','title'=>'✏️ Keep editing'],['answer'=>'save draft','title'=>'💾 Save for later']];
        } elseif ($choices) {
            $page = max(0, min($page, (int) floor((count($choices)-1)/8)));
            $buttons = array_slice($choices, $page*8, 8);
            if ($page > 0) $buttons[] = ['answer'=>'page:'.($page-1), 'title'=>'← Previous'];
            if (count($choices) > ($page+1)*8) $buttons[] = ['answer'=>'page:'.($page+1), 'title'=>'More options →'];
        } elseif ($d->status === 'saved') {
            $buttons = [['answer'=>'resume product','title'=>'▶ Resume product']];
        } elseif ($d->step === 'review') {
            $buttons = [['answer'=>'confirm and create','title'=>'✅ Create product'],['answer'=>'edit menu','title'=>'✏️ Edit details'],['answer'=>'save draft','title'=>'💾 Save for later']];
        } elseif ($d->step === 'additional_media') {
            $buttons = [['answer'=>'done','title'=>'✅ Done with photos'],['answer'=>'back','title'=>'↩ Back'],['answer'=>'save draft','title'=>'💾 Save for later']];
        } elseif (in_array($d->step,['veg','is_prescription_required','organic','basic','extra_details'],true) || preg_match('/^food_\d+_required$/',$d->step)) {
            $buttons = [['answer'=>'yes','title'=>'Yes'],['answer'=> $d->step === 'extra_details' ? 'skip' : 'no','title'=>'No']];
        } else {
            if (app(ProductFieldMap::class)->optional($d->step,$d->store)) $buttons[]=['answer'=>'skip','title'=>'Skip this step'];
            $buttons[]=['answer'=>'back','title'=>'↩ Back'];
            $buttons[]=['answer'=>'save draft','title'=>'💾 Save for later'];
            if(count($buttons)<3)$buttons[]=['answer'=>'cancel','title'=>'✖ Cancel product'];
        }
        $rows = array_map(fn($b)=>['id'=>self::id($d,$b['answer']),'title'=>$b['title']],$buttons);
        // Meta interactive body is limited to 1,024 characters. Keep full reviews intact.
        if (mb_strlen($reply)>1000) {
            for($i=0;$i<mb_strlen($reply);$i+=3500)$gateway->sendTextMessage($phone,mb_substr($reply,$i,3500));
            $reply = $d->step === 'review' ? '🛍️ Check the details above, then choose your next step.' : '🛍️ Choose below, or type your answer. Your progress is saved.';
        }
        if (count($rows)<=3) $gateway->sendButtonMessage($phone,$reply,$rows,null,'Your progress is saved • Type Support for help');
        else $gateway->sendListMessage($phone,$reply,[['title'=>'Choose an option','rows'=>$rows]],null,'Your progress is saved','Choose option');
    }

    public function review(Draft $d): string
    {
        $data=$d->data;
        $money=fn($amount)=>'₦'.number_format((float)$amount,2);
        $category=fn($id)=>app(ProductFieldMap::class)->categories($d->store)->whereKey($id)->value('name') ?: 'Not specified';
        $sections=["🛍️ *Review your product*\nNothing has been created yet.",
            "📦 *Product details*\n• *Name:* ".($data['name']??'')."\n• *Category:* ".$category($data['category_id']??null).(!empty($data['subcategory_id'])?" → ".$category($data['subcategory_id']):'')."\n\n📝 *Description*\n".($data['description']??'')];
        $pricing=["💰 *Price & stock*",'• *Price:* '.$money($data['price']??0),'• *Discount:* '.($data['discount']??0).'%'];
        if(array_key_exists('stock',$data))$pricing[]='• *Stock:* '.$data['stock'].' units';
        if(!empty($data['unit_id']))$pricing[]='• *Selling unit:* '.DB::table('units')->where('id',$data['unit_id'])->value('unit');
        $sections[]=implode("\n",$pricing);
        $variants=app(ProductFieldMap::class)->combinations($data);
        if($variants){$lines=['🎨 *Variants*'];foreach($variants as $i=>$label)$lines[]='• *'.$label.'*: '.$money($data['variant_price_'.$i]??0).' · '.($data['variant_stock_'.$i]??0).' in stock';$sections[]=implode("\n",$lines);}
        for($i=0;$i<($data['food_group_count']??0);$i++){
            $lines=['🍽️ *'.($data['food_'.$i.'_name']??'Options').'*','• '.(($data['food_'.$i.'_required']??'off')==='on'?'Required':'Optional').' · Choose '.($data['food_'.$i.'_min']??0).'–'.($data['food_'.$i.'_max']??0)];
            foreach($data['food_'.$i.'_options']??[] as $j=>$option)$lines[]='• '.$option.': +'.$money($data['food_'.$i.'_price_'.$j]??0);
            $sections[]=implode("\n",$lines);
        }
        $extra=[];
        $skip=['name','description','category_id','subcategory_id','price','discount','stock','unit_id','media_id','image','extra_details','additional_media','attribute_ids','food_group_count'];
        foreach($data as $key=>$value){
            if(in_array($key,$skip,true)||preg_match('/^(choice_|variant_|food_\d+_)/',$key)||$value===null||$value===[])continue;
            $label=ucwords(str_replace('_',' ',preg_replace('/_ids?$/','',$key)));
            if(in_array($key,['veg','is_prescription_required','organic','basic'],true)){$label=['veg'=>'Vegetarian','is_prescription_required'=>'Prescription required','organic'=>'Organic','basic'=>'Basic pharmacy item'][$key];$value=$value?'Yes':'No';}
            $table=['store_category_id'=>'store_categories','brand_id'=>'brands','condition_id'=>'common_conditions','add_ons'=>'add_ons','tax_ids'=>'taxes'][$key]??null;
            if($table){$ids=is_array($value)?$value:[$value];$value=DB::table($table)->whereIn('id',$ids)->pluck('name')->all();}
            $extra[]='• *'.$label.':* '.(is_array($value)?implode(', ',$value):$value);
        }
        if($extra)$sections[]="📋 *Additional details*\n".implode("\n",$extra);
        $sections[]='📷 *Photos*'."\n• Main photo: ".(!empty($data['media_id'])?'Saved ✅':'Not added')."\n• Extra photos: ".count($data['additional_media']??[]);
        $sections[]="✅ *Ready to submit?*\nTap *Create product* to confirm, *Edit details* to make changes, or *Save for later*.\n\n_Your product follows the store’s admin approval settings._";
        return implode("\n\n",$sections);
    }

    public function choices(Draft $d): array
    {
        if ($d->status!=='active') return [];
        $rows = match($d->step) {
            'category_id','subcategory_id' => app(ProductFieldMap::class)->categories($d->store,$d->step==='subcategory_id'?(int)($d->data['category_id']??0):0)->orderBy('id')->get(['id','name']),
            'attribute_ids' => DB::table('attributes')->orderBy('id')->get(['id','name']),
            'unit_id' => DB::table('units')->orderBy('id')->get(['id','unit as name']),
            'store_category_id' => DB::table('store_categories')->where('store_id',$d->store_id)->orderBy('id')->get(['id','name']),
            default => collect(),
        };
        $choices=$rows->map(fn($r)=>['answer'=>(string)$r->id,'title'=>$r->name])->all();
        if ($choices && app(ProductFieldMap::class)->optional($d->step,$d->store)) $choices[]=['answer'=>'skip','title'=>'Skip this step'];
        return $choices;
    }
}
