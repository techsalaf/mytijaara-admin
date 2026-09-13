<?php

namespace Modules\WhatsAppVendorConcierge\app\Observers;

use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppVendorConcierge\app\Jobs\SendVendorStatusNotification;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingEvent;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

class VendorApprovalObserver
{
    /**
     * Handle the Vendor "updated" event.
     */
    public function updated(Vendor $vendor): void
    {
        // Trigger if status changed OR if rejection_note was added/changed on a pending/rejected vendor
        if (!$vendor->wasChanged('status') && !($vendor->wasChanged('rejection_note') && (int) $vendor->status === 0)) {
            return;
        }

        $contact = WhatsAppContact::where('vendor_id', $vendor->id)->first();
        if (!$contact) {
            // Also check by phone number normalization if vendor_id wasn't linked yet
            $cleanedPhone = preg_replace('/[^0-9]/', '', (string) $vendor->phone);
            if ($cleanedPhone) {
                $contact = WhatsAppContact::where('phone_number', $cleanedPhone)
                    ->orWhere('phone_number', 'LIKE', '%' . substr($cleanedPhone, -10))
                    ->first();
                if ($contact && !$contact->vendor_id) {
                    $contact->update(['vendor_id' => $vendor->id]);
                }
            }
        }

        if (!$contact) {
            return;
        }

        if ((int) $vendor->status === 1) {
            $store = $vendor->store;
            if ($store) {
                SendVendorStatusNotification::dispatch($store->id, SendVendorStatusNotification::TYPE_APPROVED)->afterCommit();
            }
        } elseif ((int) $vendor->status === 0 && !empty($vendor->rejection_note)) {
            if ($vendor->store) {
                SendVendorStatusNotification::dispatch($vendor->store->id, SendVendorStatusNotification::TYPE_DENIED, $vendor->rejection_note)->afterCommit();
            }
        }
    }
}
