<?php

namespace App\Services;

use App\DTOs\VendorAccessDecision;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\Vendor;
use App\Models\VendorEmployee;

class VendorAuthenticationEligibility
{
    public function evaluate(?Vendor $vendor, ?Store $store, string $channel = 'api', ?VendorEmployee $employee = null): VendorAccessDecision
    {
        if (! in_array($channel, ['api', 'web'], true)) {
            return $this->deny('token_scope_invalid');
        }
        if (! $vendor || ! $store || (int) $store->vendor_id !== (int) $vendor->id
            || $vendor->getAttribute('deleted_at') || $store->getAttribute('deleted_at')) {
            return $this->deny('vendor_inactive');
        }
        if ($employee && ((int) $employee->vendor_id !== (int) $vendor->id
            || (int) $employee->store_id !== (int) $store->id || $employee->getAttribute('deleted_at')
            || ($employee->getAttribute('status') !== null && (int) $employee->status !== 1))) {
            return $this->deny('vendor_inactive');
        }
        if ($vendor->status === null) {
            return $this->deny('vendor_pending_approval', ! $employee && (int) $store->status === 0
                && $store->store_business_model === 'none');
        }
        if ((string) $vendor->status !== '1') {
            return $this->deny(trim((string) $vendor->rejection_note) !== '' ? 'vendor_rejected' : 'vendor_inactive');
        }
        if ((string) $store->status !== '1') {
            return $this->deny('vendor_suspended');
        }
        if ($store->module?->module_type === 'rental' && ! addon_published_status('Rental')) {
            return $this->deny('vendor_inactive');
        }
        if ($store->module?->module_type === 'service' && ! addon_published_status('Service')) {
            return $this->deny('vendor_inactive');
        }
        if (in_array($store->store_business_model, ['none', 'unsubscribed'], true)) {
            return $this->deny('subscription_setup_required', ! $employee);
        }
        if ($store->store_business_model === 'subscription') {
            $subscription = StoreSubscription::withoutGlobalScopes()->where('store_id', $store->id)
                ->where('status', 1)->orderByDesc('id')->first();
            if (! $subscription || ! $subscription->expiry_date || $subscription->expiry_date < now()->toDateString()) {
                return $this->deny('subscription_setup_required', ! $employee);
            }
            if ($channel === 'api' && (int) $subscription->mobile_app !== 1) {
                return $this->deny('subscription_mobile_unavailable');
            }
        } elseif ($store->store_business_model !== 'commission') {
            return $this->deny('vendor_inactive');
        }

        return new VendorAccessDecision(true, 'vendor_eligible', 'eligible');
    }

    private function deny(string $reason, bool $setup = false): VendorAccessDecision
    {
        return new VendorAccessDecision(false, $reason, $reason, $setup);
    }
}
