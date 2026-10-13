from pathlib import Path
def put(p,s):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(s,encoding='utf-8')
put('Modules/WhatsAppVendorConcierge/app/Services/VendorAccessNoticeService.php',r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Services;
use App\Models\Store;
use Illuminate\Support\Facades\{Cache,DB,Mail};
use Modules\WhatsAppVendorConcierge\app\Models\{WhatsAppContact,WhatsAppMessage,ConciergeRecoveryAudit};
use Modules\WhatsAppVendorConcierge\app\Mail\VendorAccessMail;
class VendorAccessNoticeService
{
    public function details(Store $store): array
    {
        $tab=DB::table('data_settings')->where('key','store_login_url')->value('value') ?: 'vendor';
        return ['name'=>$store->name,'id'=>$store->id,'module'=>$store->module?->module_name,
            'email'=>$store->vendor?->email,'address'=>$store->address,'login_url'=>url('/login/'.rawurlencode($tab)),
            'delivery'=>$store->sub_self_delivery ? 'You arrange delivery' : 'Platform delivery',
            'support_url'=>'https://wa.me/'.preg_replace('/\D+/','',config('whatsapp-vendor-concierge.support.whatsapp_number')).'?text=Hello%20MyTijaara%20Support%2C%20I%20need%20help'];
    }
    public function message(array $d): string
    {
        return "Hello! Here are your MyTijaara shop details:\n\n*{$d['name']}* • Store #{$d['id']}\nModule: {$d['module']}\nLogin email: {$d['email']}\nDelivery: {$d['delivery']}\n\nManage products, orders and settings: {$d['login_url']}\nUse your existing password, or Forgot Password. Never share your password in WhatsApp.\n\nAdd this login page to your phone's Home Screen for quick access. We're working on the vendor mobile app and will announce when it is ready. Thanks for your patience! Check your email for the full guide.\n\nHuman support: {$d['support_url']}";
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
''')
put('Modules/WhatsAppVendorConcierge/app/Mail/VendorAccessMail.php',r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Mail;
use Illuminate\Mail\Mailable;
class VendorAccessMail extends Mailable
{
    public function __construct(public array $details) {}
    public function build() {return $this->subject('Your MyTijaara shop: login details and getting started')->view('whatsappvendorconcierge::emails.vendor-access',['d'=>$this->details]);}
}
''')
put('Modules/WhatsAppVendorConcierge/resources/views/emails/vendor-access.blade.php',r'''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f6f5;font-family:Arial,sans-serif;color:#24352e">
<div style="max-width:620px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden">
<div style="background:#087e68;color:white;padding:28px"><h1 style="margin:0;font-size:26px">Your shop is ready for you</h1><p style="margin-bottom:0">Welcome to MyTijaara, {{ $d['name'] }}.</p></div>
<div style="padding:28px;line-height:1.65"><p>Hello,</p><p>Thank you for building your business with MyTijaara. Your vendor dashboard gives you access to the tools you may not yet be able to use through WhatsApp.</p>
<table style="width:100%;border-collapse:collapse"><tr><td>Business</td><td><strong>{{ $d['name'] }}</strong></td></tr><tr><td>Store ID</td><td>{{ $d['id'] }}</td></tr><tr><td>Module</td><td>{{ $d['module'] }}</td></tr><tr><td>Login email</td><td>{{ $d['email'] }}</td></tr><tr><td>Delivery</td><td>{{ $d['delivery'] }}</td></tr></table>
<p style="margin:26px 0"><a href="{{ $d['login_url'] }}" style="display:inline-block;background:#087e68;color:white;text-decoration:none;padding:12px 22px;border-radius:6px">Open your vendor dashboard</a></p><p>Your permanent login link:<br><a href="{{ $d['login_url'] }}">{{ $d['login_url'] }}</a></p>
<p>Sign in with the email above and the password you set during registration. If you cannot remember it, use <strong>Forgot Password</strong> on the login page. We will never ask you to send your password in WhatsApp or email.</p>
<h2 style="font-size:20px">What to do next</h2><ol><li>Check your shop information, logo, opening hours and contact details.</li><li>Add or update products, photos, prices and stock.</li><li>Review orders and keep customers informed as you fulfil them.</li><li>Review your delivery charges and arrange reliable delivery. Store-managed delivery lets you use your own arrangements; please still update order statuses and complete required delivery verification.</li></ol>
<h2 style="font-size:20px">Keep your shop one tap away</h2><p><strong>Android:</strong> open the login link in Chrome, tap the three-dot menu and choose <em>Add to Home screen</em> (or <em>Install app</em>, if offered).</p><p><strong>iPhone:</strong> open it in Safari, tap Share, then <em>Add to Home Screen</em>. Menu names may vary by browser.</p>
<p>We are working hard on a dedicated vendor mobile app and hope to make it available soon. Thank you for bearing with us; we will announce its availability when it is ready. Your browser dashboard is available in the meantime.</p>
<p>Need a person? <a href="{{ $d['support_url'] }}">Chat with MyTijaara Support</a>. The support chat is separate, so you can continue using the concierge.</p><p>Thank you for being part of MyTijaara.<br><strong>The MyTijaara Team</strong></p></div></div></body></html>
''')
put('Modules/WhatsAppVendorConcierge/app/Console/Commands/VendorAccessNoticeCommand.php',r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Store;
use Modules\WhatsAppVendorConcierge\app\Services\VendorAccessNoticeService;
class VendorAccessNoticeCommand extends Command
{
    protected $signature='whatsapp:vendor-access-notice {--store=*} {--force}';
    protected $description='Preview or send audited launch access notices to approved active stores';
    public function handle(VendorAccessNoticeService $service): int
    {
        $query=Store::where('status',1)->where('active',1)->whereHas('vendor',fn($q)=>$q->where('status',1));
        if ($this->option('store')) $query->whereIn('id',$this->option('store'));
        $failed=false;
        foreach($query->get() as $store)foreach(['email','whatsapp'] as $channel){$r=$service->send($store,$channel,!$this->option('force'));$this->line(json_encode($r));$failed=$failed||in_array($r['status'],['failed','uncertain']);}
        return $failed?1:0;
    }
}
''')
p=Path('Modules/WhatsAppVendorConcierge/app/Providers/WhatsAppVendorConciergeServiceProvider.php');s=p.read_text().replace('$this->commands([','$this->commands([\n            \\Modules\\WhatsAppVendorConcierge\\app\\Console\\Commands\\VendorAccessNoticeCommand::class,',1);p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationCommands.php');s=p.read_text().replace("            'status' =>", "            'shop_details' => ['show my shop details', 'show me my shop details', 'my shop details'],\n            'status' =>");p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationOrchestrator.php');s=p.read_text().replace("            'status', 'check_status' =>", "            'shop_details' => $this->manager->showShopDetails($conversation, $contact, $gateway),\n            'status', 'check_status' =>");p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationManager.php');s=p.read_text();pos=s.index('    public function initiateHumanHandoff');s=s[:pos]+'''    public function showShopDetails(WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): void
    {
        $store=$contact->vendor?->store;
        if (!$store || (int)$contact->vendor?->status !== 1) { $this->checkApplicationStatus($conversation,$contact,$gateway); return; }
        $service=app(VendorAccessNoticeService::class);
        $gateway->sendTextMessage($contact->phone_number,$service->message($service->details($store)));
    }

'''+s[pos:];s=s.replace('Update physical address or GPS pin','Update dispatch address or GPS pin');p.write_text(s)
