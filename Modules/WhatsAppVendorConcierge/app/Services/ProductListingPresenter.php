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
        return 'pd:'.$d->id.':'.substr(hash('sha256', $d->step.'|'.json_encode($d->data)), 0, 10).':'.rawurlencode($answer);
    }

    public static function decode(Draft $d, string $id): ?string
    {
        $prefix = self::id($d, '');
        return str_starts_with($id, $prefix) ? rawurldecode(substr($id, strlen($prefix))) : null;
    }

    public function send(WhatsAppGateway $gateway, string $phone, string $reply, Draft $d, int $page = 0): void
    {
        $choices = $this->choices($d);
        if (str_starts_with($reply,'✏️ Choose')) {
            $choices = collect(app(ProductFieldMap::class)->steps($d->store,$d->data))->filter(fn($f)=>in_array($f,['name','image','category_id','description','price','stock','discount','additional_media'],true))->map(fn($f)=>['answer'=>'edit '.$f,'title'=>ucwords(str_replace(['_id','_'],['',' '],$f))])->values()->all();
        }
        $buttons = [];
        if ($choices) {
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
