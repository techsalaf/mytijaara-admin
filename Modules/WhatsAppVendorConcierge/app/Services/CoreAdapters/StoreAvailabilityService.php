<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\Models\Store;
use Illuminate\Support\Facades\DB;

class StoreAvailabilityService
{
    /** Canonical merchant-controlled open/close flag; status remains admin-owned. */
    public function set(Store $store, int $vendorId, bool $active): Store
    {
        return DB::transaction(function () use ($store, $vendorId, $active) {
            $locked = Store::whereKey($store->id)->where('vendor_id', $vendorId)->lockForUpdate()->firstOrFail();
            $locked->active = $active;
            if ($locked->isDirty('active')) {
                $locked->save();
            }
            return $locked;
        });
    }
}
