<?php

namespace App\Services;

use App\Events\VendorApplicationStatusChanged;
use App\Models\BusinessSetting;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorApplicationDecisionService
{
    public function decide(int $storeId, int $status, ?string $reason = null): ?Store
    {
        if (!in_array($status, [0, 1], true) || ($status === 0 && trim($reason ?? '') === '')) {
            throw ValidationException::withMessages(['status' => 'Choose approval or provide a rejection reason.']);
        }
        return DB::transaction(function () use ($storeId, $status, $reason) {
            $store = Store::lockForUpdate()->findOrFail($storeId);
            $vendor = Vendor::lockForUpdate()->findOrFail($store->vendor_id);
            if ($vendor->status !== null && (int) $vendor->status === $status) return null;

            $vendor->status = $status;
            $vendor->rejection_note = $status === 0 ? trim($reason) : null;
            // Publish one domain event after both records are saved; suppress the fallback observer.
            $vendor->saveQuietly();
            $store->setRelation('vendor', $vendor);
            $store->status = $status;
            if ($status === 1 && ($subscription = $store->store_sub_update_application)) {
                $days = $subscription->is_trial
                    ? (BusinessSetting::where('key', 'subscription_free_trial_days')->value('value') ?? 1)
                    : $subscription->validity;
                $subscription->update(['expiry_date' => now()->addDays((int) $days)->format('Y-m-d'), 'status' => 1]);
                $store->store_business_model = 'subscription';
            }
            $store->save();
            // Dispatch while the transaction is open: the listener queues after commit.
            // Provider requests run in the worker, never inside the admin HTTP request.
            event(new VendorApplicationStatusChanged($store, $vendor, $status === 1 ? 'approved' : 'denied', $vendor->rejection_note));
            return $store;
        });
    }
}
