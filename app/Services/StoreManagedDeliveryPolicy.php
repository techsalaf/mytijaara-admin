<?php
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
