<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Module;
use App\Models\Zone;
use App\Models\SubscriptionPackage;
use App\Models\BusinessSetting;
use App\CentralLogics\Helpers;

class FlowOptions
{
    public function modules(): array
    {
        return $this->options(Module::active()->notParcel()->notRideShare()->get(), 'module_name', 200);
    }

    public function zones(?int $moduleId): array
    {
        if (! $moduleId) {
            return [];
        }

        return $this->options(Zone::active()->whereIn('id', \App\Models\ModuleZone::where('module_id', $moduleId)->select('zone_id'))->get(), 'name', 200);
    }

    public function pickups(): array
    {
        return $this->options(Zone::active()->get(), 'name', 20);
    }

    public function packages(?int $moduleId): array
    {
        $module = Module::active()->notParcel()->notRideShare()->find($moduleId);
        if (! $module) {
            return [];
        }

        return $this->options(SubscriptionPackage::where('status', 1)->where('module_type', Helpers::subscriptionPackageType($module))->get(), 'package_name', 200);
    }

    public function plans(): array
    {
        $sub = (BusinessSetting::where('key', 'subscription_business_model')->value('value') ?? '1') == '1';
        $commission = ! $sub || (BusinessSetting::where('key', 'commission_business_model')->value('value') ?? '1') == '1';
        $out = [];
        if ($commission) {
            $out[] = ['id' => 'commission-base', 'title' => 'Commission', 'enabled' => true];
        }
        if ($sub) {
            $out[] = ['id' => 'subscription-base', 'title' => 'Subscription', 'enabled' => true];
        }

        return $out;
    }

    private function options($rows, string $label, int $limit): array
    {
        if ($rows->count() > $limit) {
            throw new \RuntimeException('Eligible options exceed Flow component limits; use chat fallback.');
        }

        return $rows->map(fn ($r) => ['id' => (string) $r->id, 'title' => mb_substr((string) $r->$label, 0, 30), 'enabled' => true])->values()->all();
    }
}
