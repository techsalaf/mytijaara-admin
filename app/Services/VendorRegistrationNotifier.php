<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Mail\StoreRegistration;
use App\Mail\VendorSelfRegistration;
use App\Models\Admin;
use App\Models\Module;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Rental\Emails\ProviderRegistration;
use Modules\Rental\Emails\ProviderSelfRegistration;
use Modules\Service\Emails\ProviderRegistration as ServiceProviderRegistration;
use Modules\Service\Emails\ProviderSelfRegistration as ServiceProviderSelfRegistration;

/** Called after the outermost registration transaction commits. */
class VendorRegistrationNotifier
{
    public function send(Vendor $vendor, Module $module): void
    {
        if (! config('mail.status')) {
            return;
        }
        $name = $vendor->f_name.' '.$vendor->l_name;
        $admin = Admin::where('role_id', 1)->first();
        $rental = $module->module_type === 'rental';
        $service = $module->module_type === 'service';
        $messages = [];
        if (! $rental && ! $service) {
            if (Helpers::get_mail_status('registration_mail_status_store') == '1'
                && Helpers::getNotificationStatusData('store', 'store_registration', 'mail_status')) {
                $messages[] = [$vendor->email, new VendorSelfRegistration('pending', $name)];
            }
            if (Helpers::get_mail_status('store_registration_mail_status_admin') == '1'
                && Helpers::getNotificationStatusData('admin', 'store_self_registration', 'mail_status')) {
                $messages[] = [$admin?->getRawOriginal('email'), new StoreRegistration('pending', $name)];
            }
        } elseif ($rental && addon_published_status('Rental')) {
            if (Helpers::get_mail_status('rental_registration_mail_status_provider') == '1'
                && Helpers::getRentalNotificationStatusData('provider', 'provider_registration', 'mail_status')) {
                $messages[] = [$vendor->email, new ProviderSelfRegistration('pending', $name)];
            }
            if (Helpers::get_mail_status('rental_provider_registration_mail_status_admin') == '1'
                && Helpers::getRentalNotificationStatusData('admin', 'provider_self_registration', 'mail_status')) {
                $messages[] = [$admin?->getRawOriginal('email'), new ProviderRegistration('pending', $name)];
            }
        } elseif ($service && addon_published_status('Service')) {
            if (Helpers::get_mail_status('service_registration_mail_status_provider') == '1'
                && Helpers::getServiceNotificationStatusData('provider', 'service_provider_registration', 'mail_status')) {
                $messages[] = [$vendor->email, new ServiceProviderSelfRegistration('pending', $name)];
            }
            if (Helpers::get_mail_status('service_provider_registration_mail_status_admin') == '1'
                && Helpers::getServiceNotificationStatusData('admin', 'service_provider_self_registration', 'mail_status')) {
                $messages[] = [$admin?->getRawOriginal('email'), new ServiceProviderRegistration('pending', $name)];
            }
        }
        foreach ($messages as [$recipient, $message]) {
            if (! $recipient) {
                continue;
            }
            try {
                Mail::to($recipient)->send($message);
            } catch (\Throwable $error) {
                Log::warning('Registration notification failed after commit', [
                    'vendor_id' => $vendor->id, 'notification' => $message::class, 'exception' => $error::class,
                ]);
            }
        }
    }
}
