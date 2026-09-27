<?php
namespace Modules\WhatsAppVendorConcierge\app\Services;
use App\Models\Store;
use Illuminate\Support\Facades\{Cache,DB,Mail};
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppContact,WhatsAppMessage,ConciergeRecoveryAudit};
use Modules\WhatsAppVendorConcierge\app\Mail\VendorAccessMail;
class VendorAccessNoticeService
{
    public function loginUrl(): string
    {
        $tab=\Illuminate\Support\Facades\Schema::hasTable('data_settings') ? DB::table('data_settings')->where('key','store_login_url')->value('value') : null;
        return url('/login/'.rawurlencode($tab ?: 'vendor'));
    }
    public function details(Store $store): array
    {
        return ['name'=>$store->name,'id'=>$store->id,'module'=>$store->module?->module_name,
            'email'=>$store->vendor?->email,'address'=>$store->address,'plan'=>ucfirst($store->store_business_model),'login_url'=>$this->loginUrl(),
            'delivery'=>$store->sub_self_delivery ? 'You arrange delivery' : 'Platform delivery',
            'support_url'=>'https://wa.me/'.preg_replace('/\D+/','',config('whatsapp-vendor-concierge.support.whatsapp_number')).'?text=Hello%20MyTijaara%20Support%2C%20I%20need%20help'];
    }
    public function message(array $d): string
    {
        return "🏪 *Your MyTijaara shop*\n\n*{$d['name']}*\n• 🛍️ Module: {$d['module']}\n• 📍 Dispatch address: {$d['address']}\n• 📋 Plan: {$d['plan']}\n• 🚚 Delivery: {$d['delivery']}\n\n🔐 *Dashboard access*\n• Login email: {$d['email']}\n{$d['login_url']}\n\nUse your existing password or *Forgot Password*. Never share your password in WhatsApp.\n\n📱 *Quick access*\nAdd the login page to your phone’s Home Screen. We’re working on the vendor mobile app and will announce when it is ready.\n\n💬 *Human support*\n{$d['support_url']}";
    }
    public function send(Store $store, string $channel, bool $dryRun=true): array
    {
        $action='vendor_access_20260926_'.$channel.'_'.$store->id;
        return Cache::lock($action,120)->block(5,function () use ($store,$channel,$dryRun,$action) {
            $store->refresh()->load(['vendor','module']);
            $contact=WhatsAppContact::where('vendor_id',$store->vendor_id)->first();
            $conversation=$contact?->activeConversation()->first();
            $inbound=$conversation?->messages()->where('direction','inbound')->latest('created_at')->first();
            $reason=null;
            if ((int)$store->vendor?->status!==1 || !$store->status || !$store->active) $reason='Store is not active and approved';
            elseif (ConciergeRecoveryAudit::where('action',$action)->where('is_dry_run',false)->whereIn('status',['sending','success','uncertain'])->exists()) $reason='Already sent or awaiting manual verification';
            elseif ($channel==='email' && !filter_var($store->vendor->email,FILTER_VALIDATE_EMAIL)) $reason='No valid vendor email';
            elseif ($channel==='whatsapp' && (!$contact || $contact->is_blocked || !$inbound || $inbound->created_at->lte(now()->subHours(24)))) $reason='No linked contact/open WhatsApp reply window; approved suitable template and consent required';
            elseif (!in_array($channel,['email','whatsapp'],true)) $reason='Unknown channel';
            $result=['store_id'=>$store->id,'channel'=>$channel,'status'=>$reason?'excluded':($dryRun?'dry_run_passed':'sending'),'reason'=>$reason];
            $audit=ConciergeRecoveryAudit::create(['conversation_id'=>$conversation?->id,'contact_id'=>$contact?->id,'action'=>$action,'initiated_by'=>'cli','is_dry_run'=>$dryRun,'status'=>$result['status'],'reason'=>$reason??'Authorized launch account-access notice','details'=>['store_id'=>$store->id],'correlation_id'=>'ACCESS-'.\Illuminate\Support\Str::uuid()]);
            if ($reason || $dryRun) return $result;
            try {
                $details=$this->details($store);
                if ($channel==='email') Mail::to($store->vendor->email)->send(new VendorAccessMail($details));
                else {
                    $response=app(WhatsAppGateway::class)->sendTextMessage($contact->phone_number,$this->message($details));
                    if (empty($response['messages'][0]['id'])) { $audit->update(['status'=>'failed']); return array_merge($result,['status'=>'failed']); }
                    $audit->details=['store_id'=>$store->id,'message_id'=>$response['messages'][0]['id']];
                }
                $audit->status='success';$audit->save();$result['status']='success';
            } catch (\Throwable $e) {
                // Delivery may have happened before a transport error: do not blindly resend.
                $audit->update(['status'=>'uncertain','details'=>['store_id'=>$store->id,'exception'=>$e::class]]);
                $result['status']='uncertain';
            }
            return $result;
        });
    }
}
