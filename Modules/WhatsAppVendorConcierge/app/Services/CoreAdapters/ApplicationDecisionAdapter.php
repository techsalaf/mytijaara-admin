<?php
namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\CentralLogics\Helpers;
use App\Mail\VendorSelfRegistration;
use App\Services\VendorApplicationDecisionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ApplicationDecisionAdapter
{
    public function decide(int $storeId, int $status, ?string $reason = null): bool
    {
        try {
            $store = app(VendorApplicationDecisionService::class)->decide($storeId, $status, $reason);
        } catch (\Throwable $error) {
            Log::error('Application decision failed', ['store_id' => $storeId, 'exception' => $error::class]);
            return false;
        }
        if (!$store) return true;
        $approved = $status === 1;
        try {
            if (config('mail.status') && Helpers::get_mail_status($approved ? 'approve_mail_status_store' : 'deny_mail_status_store') == '1'
                && Helpers::getNotificationStatusData('store', $approved ? 'store_registration_approval' : 'store_registration_deny', 'mail_status')) {
                Mail::to($store->vendor->getRawOriginal('email'))->send(new VendorSelfRegistration($approved ? 'approved' : 'denied', $store->vendor->f_name.' '.$store->vendor->l_name));
            }
        } catch (\Throwable $error) {
            Log::error('Application decision email failed', ['store_id' => $storeId, 'exception' => $error::class]);
        }
        // The host decision event is the only WhatsApp notification producer.
        return true;
    }
}
