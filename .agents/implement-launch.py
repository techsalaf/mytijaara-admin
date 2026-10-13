from pathlib import Path
import json
root=Path.cwd()
def put(p,s):
 p=Path(p);p.parent.mkdir(parents=True,exist_ok=True);p.write_text(s,encoding='utf-8')
put('app/Services/StoreManagedDeliveryPolicy.php',r'''<?php
// GEMINI-MYTJ START: Channel-neutral global delivery policy; absent setting preserves upstream behavior.
namespace App\Services;
use App\Models\Store;
use Illuminate\Support\Facades\{Cache, DB};
class StoreManagedDeliveryPolicy
{
    public const KEY = 'mytijaara_store_managed_delivery';
    public const TYPES = ['grocery','ecommerce','food','pharmacy'];
    public static function mode(): ?int
    {
        $value = Cache::remember(self::KEY, 60, fn () => DB::table('business_settings')->where('key',self::KEY)->value('value'));
        return in_array((string)$value,['0','1'],true) ? (int)$value : null;
    }
    public static function forStore(Store $store): ?int
    {
        $mode=self::mode();
        if ($mode === null || !$store->module_id) return null;
        return in_array($store->module?->module_type,self::TYPES,true) ? $mode : null;
    }
    public function apply(bool $enabled): int
    {
        return Cache::lock(self::KEY.'-write',30)->block(5,function () use ($enabled) {
            $count=DB::transaction(function () use ($enabled) {
                DB::table('business_settings')->updateOrInsert(['key'=>self::KEY],['value'=>$enabled?'1':'0']);
                $modules=DB::table('modules')->whereIn('module_type',self::TYPES)->pluck('id');
                return DB::table('stores')->whereIn('module_id',$modules)->update(['self_delivery_system'=>(int)$enabled]);
            });
            Cache::forget(self::KEY);
            Cache::forget('business_settings_all_data');
            return $count;
        });
    }
}
// GEMINI-MYTJ END: Global delivery policy.
''')
p=Path('app/Models/Store.php');s=p.read_text();s=s.replace('public function getSubSelfDeliveryAttribute(): mixed\n    {','public function getSubSelfDeliveryAttribute(): mixed\n    {\n        // GEMINI-MYTJ START: Global delivery override includes subscription stores.\n        $globalDelivery = \\App\\Services\\StoreManagedDeliveryPolicy::forStore($this);\n        if ($globalDelivery !== null) return $globalDelivery;\n        // GEMINI-MYTJ END: Global delivery override.');s=s.replace('static::addGlobalScope(new ZoneScope);','''static::addGlobalScope(new ZoneScope);
        // GEMINI-MYTJ START: New and edited stores inherit the global delivery policy.
        static::saving(function (Store $store) {
            $mode = \\App\\Services\\StoreManagedDeliveryPolicy::forStore($store);
            if ($mode !== null) $store->self_delivery_system = $mode;
        });
        // GEMINI-MYTJ END: Global delivery inheritance.''',1);p.write_text(s)
p=Path('scripts/core-patches.json');d=json.loads(p.read_text());
for name in ['app/Models/Store.php','app/Services/StoreManagedDeliveryPolicy.php']:
 d['patches'][name]={'purpose':'Global store-managed delivery override for supported commerce modules, including subscriptions and new stores','reason_in_core':'Delivery behavior must be shared by mobile, web, and optional channels without module dependencies','upstream_behavior':'No change when policy is absent; explicit global on/off overrides individual and subscription delivery settings','tests':['LaunchOperationsTest'],'upgrade':'Reapply GEMINI-MYTJ blocks and generic service; verify delivery module types and subscription accessor','owner':'MyTijaara backend maintainers'}
p.write_text(json.dumps(d,indent=2)+'\n')
p=Path('Modules/WhatsAppVendorConcierge/app/Services/ConversationManager.php');s=p.read_text();a=s.index('        $support = app(SupportCaseService::class);',s.index('public function initiateHumanHandoff'));b=s.index('\n    }',a);s=s[:a]+'''        $number = preg_replace('/\\D+/', '', (string) config('whatsapp-vendor-concierge.support.whatsapp_number'));
        if (!$number) {
            $gateway->sendTextMessage($contact->phone_number, 'Please contact '.config('whatsapp-vendor-concierge.support.email').' for support. You can keep using this concierge.');
            return;
        }
        $text = 'Hello MyTijaara Support, I need help with '.($contact->vendor?->store?->name ?? 'my account').'. Reference: WA-'.$conversation->id;
        $url = 'https://wa.me/'.$number.'?text='.rawurlencode($text);
        $gateway->sendCtaUrlMessage($contact->phone_number,
            'Our human support team can help on +'.$number.'. Tap below to open a separate WhatsApp chat, then press Send. You can keep using this concierge for your shop or registration.',
            'Talk to Support', $url);
''' + s[b:];s=s.replace('Assalaamu Alaikum! Welcome back to MyTijaara.','Welcome back to MyTijaara.').replace('Assalaamu Alaikum 👋','Hello 👋');p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/config/config.php');s=p.read_text().replace("    'api' => [","    'support' => ['whatsapp_number' => env('MYTIJAARA_SUPPORT_WHATSAPP', '2347049147825'), 'email' => 'hello@mytijaara.com'],\n\n    'api' => [",1);p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php');s=p.read_text().replace('Where is your shop physically located?','Where do you operate or dispatch orders from? Online-only and home-based businesses are welcome.').replace('full physical shop address','operating or dispatch address').replace('full physical street address','real operating or dispatch address').replace('[Section 2 of 5: Store Location]','[Section 2 of 5: Business Location]');p.write_text(s)
p=Path('Modules/WhatsAppVendorConcierge/app/Agents/VendorConciergeAgent.php');s=p.read_text().replace('===== YOUR CAPABILITIES =====','''Use inclusive, neutral greetings such as Hello or Welcome. Never add religious greetings, blessings, or assume a religion. Online-only and home-based businesses can register using a real operating/dispatch address and supported zone. Do not imply a walk-in shop is required. For human support, share https://wa.me/2347049147825?text=Hello%20MyTijaara%20Support%2C%20I%20need%20help. Explain that sending a message there does not stop this concierge. Never claim a mobile app release date.

===== YOUR CAPABILITIES =====''');p.write_text(s)

