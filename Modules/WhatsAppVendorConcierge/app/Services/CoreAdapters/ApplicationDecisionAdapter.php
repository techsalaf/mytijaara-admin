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
        if (!config('mail.status')) return true;
        $approved = $status === 1;
        try {
            $rental = $store->module?->module_type === 'rental';
            $setting = $rental ? ($approved ? 'rental_approve_mail_status_provider' : 'rental_deny_mail_status_provider')
                : ($approved ? 'approve_mail_status_store' : 'deny_mail_status_store');
            $enabled = $rental
                ? Helpers::getRentalNotificationStatusData('provider', $approved ? 'provider_registration_approval' : 'provider_registration_deny', 'mail_status')
                : Helpers::getNotificationStatusData('store', $approved ? 'store_registration_approval' : 'store_registration_deny', 'mail_status');
            if (config('mail.status') && Helpers::get_mail_status($setting) == '1' && $enabled) {
                $mail = $rental ? \Modules\Rental\Emails\ProviderSelfRegistration::class : VendorSelfRegistration::class;
                Mail::to($store->vendor->getRawOriginal('email'))->send(new $mail($approved ? 'approved' : 'denied', $store->vendor->f_name.' '.$store->vendor->l_name));
            }
        } catch (\Throwable $error) {
            Log::error('Application decision email failed', ['store_id' => $storeId, 'exception' => $error::class]);
        }
        // The host decision event is the only WhatsApp notification producer.
        return true;
    }
}
