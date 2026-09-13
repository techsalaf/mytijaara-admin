<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\CentralLogics\StoreLogic;
use App\DTOs\VendorApplicationDTO;
use App\Mail\ProviderRegistration;
use App\Mail\ProviderSelfRegistration;
use App\Mail\StoreRegistration;
use App\Mail\VendorSelfRegistration;
use App\Models\Admin;
use App\Models\Module;
use App\Models\ModuleZone;
use App\Models\Store;
use App\Models\SubscriptionPackage;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Services\MediaPolicyService;

class VendorApplicationService
{
    public function __construct(
        protected MediaPolicyService $mediaPolicy
    ) {}

    /**
     * Submit a vendor application with canonical validation, persistence, and parity.
     * Used by both Web VendorController and WhatsApp VendorOnboardingService.
     *
     * @return array{vendor: Vendor, store: Store}
     * @throws ValidationException
     */
    public function submit(VendorApplicationDTO $dto): array
    {
        $this->validateDto($dto);

        return DB::transaction(function () use ($dto) {
            $existingVendor = Vendor::where('phone', $dto->phone)->first();
            $existingVendorByEmail = Vendor::where('email', $dto->email)->first();

            if ($existingVendorByEmail && (!$existingVendor || $existingVendorByEmail->id !== $existingVendor->id)) {
                throw ValidationException::withMessages([
                    'email' => translate('messages.email_already_exists'),
                ]);
            }

            if ($existingVendor) {
                $existingStore = Store::where('vendor_id', $existingVendor->id)->first();
                if ($existingStore && (int) $existingStore->status === 1) {
                    throw ValidationException::withMessages([
                        'phone' => translate('messages.an_approved_store_already_exists_for_this_phone'),
                    ]);
                }

                $vendor = $existingVendor;
                $vendor->f_name = $dto->f_name;
                $vendor->l_name = $dto->l_name;
                $vendor->email = $dto->email;
                if (!empty($dto->password)) {
                    $vendor->password = bcrypt($dto->password);
                } elseif (!empty($dto->password_hash)) {
                    $vendor->password = $dto->password_hash;
                }
                $vendor->status = null; // null = pending admin approval
                $vendor->save();
            } else {
                $vendor = new Vendor();
                $vendor->f_name = $dto->f_name;
                $vendor->l_name = $dto->l_name;
                $vendor->email = $dto->email;
                $vendor->phone = $dto->phone;
                $vendor->password = !empty($dto->password)
                    ? bcrypt($dto->password)
                    : (!empty($dto->password_hash) ? $dto->password_hash : bcrypt(\Illuminate\Support\Str::random(16)));
                $vendor->status = null; // null = pending admin approval
                $vendor->save();
            }

            $module = Module::find($dto->module_id);
            $store = Store::where('vendor_id', $vendor->id)->first() ?? new Store();

            $store->name = $dto->business_name;
            $store->phone = $dto->phone;
            $store->email = $dto->email;
            $store->address = $dto->address;
            $store->latitude = $dto->latitude;
            $store->longitude = $dto->longitude;
            $store->vendor_id = $vendor->id;
            $store->zone_id = $dto->zone_id;
            $store->module_id = $dto->module_id;
            $store->pickup_zone_id = json_encode(!empty($dto->pickup_zone_ids) ? array_values($dto->pickup_zone_ids) : []);
            $store->tin = $dto->tin ?? $dto->cac_number;
            $store->tin_expire_date = $dto->tin_expire_date;
            $store->delivery_time = $dto->minimum_delivery_time . '-' . $dto->maximum_delivery_time . ' ' . $dto->delivery_time_type;
            $store->status = 0; // inactive / pending admin approval

            // Business model: stays 'none' for subscription until paid; 'commission' for commission-base
            if (Helpers::subscription_check() && $dto->business_plan === 'subscription-base' && $dto->package_id !== null) {
                $store->store_business_model = 'none';
                $store->package_id = $dto->package_id;
            } else {
                $store->store_business_model = 'commission';
                $store->package_id = null;
            }

            // Media placement
            $this->applyBrandingMedia($store, $dto);

            $store->save();

            // Store translations
            $dummyRequest = new Request([
                'lang' => $dto->lang,
                'name' => array_fill(0, count($dto->lang), $dto->business_name),
                'address' => array_fill(0, count($dto->lang), $dto->address),
            ]);
            Helpers::add_or_update_translations(request: $dummyRequest, key_data: 'name', name_field: 'name', model_name: 'Store', data_id: $store->id, data_value: $store->name);
            Helpers::add_or_update_translations(request: $dummyRequest, key_data: 'address', name_field: 'address', model_name: 'Store', data_id: $store->id, data_value: $store->address);

            // Operating hours / schedule insertion
            if ($module && isset(config('module.' . $module->module_type)['always_open']) && config('module.' . $module->module_type)['always_open']) {
                StoreLogic::insert_schedule($store->id);
            }

            // Emails
            $this->sendRegistrationEmails($vendor, $store, $module);

            return [
                'vendor' => $vendor,
                'store' => $store,
            ];
        });
    }

    /**
     * Canonical validation rules before submission.
     */
    protected function validateDto(VendorApplicationDTO $dto): void
    {
        $messages = [];

        if (empty(trim($dto->business_name))) {
            $messages['name'] = translate('messages.store_name_is_required');
        }
        if (empty(trim($dto->address))) {
            $messages['address'] = translate('messages.address_is_required');
        }
        if (empty(trim($dto->phone))) {
            $messages['phone'] = translate('messages.phone_is_required');
        }
        if (empty(trim($dto->email)) || !filter_var($dto->email, FILTER_VALIDATE_EMAIL)) {
            $messages['email'] = translate('messages.valid_email_is_required');
        }

        // Zone validation (spatial check when running on MySQL/PostGIS)
        if ($dto->zone_id) {
            $zone = Zone::find($dto->zone_id);
            if (!$zone) {
                $messages['zone_id'] = translate('messages.zone_not_found');
            } elseif (config('database.default') !== 'sqlite' && class_exists(Point::class) && defined('POINT_SRID')) {
                try {
                    $inZone = Zone::query()
                        ->whereContains('coordinates', new Point($dto->latitude, $dto->longitude, POINT_SRID))
                        ->where('id', $dto->zone_id)
                        ->exists();
                    if (!$inZone) {
                        $messages['latitude'] = translate('messages.coordinates_out_of_zone');
                    }
                } catch (\Throwable $e) {
                    Log::warning('Spatial coordinate check skipped: ' . $e->getMessage());
                }
            }
        } else {
            $messages['zone_id'] = translate('messages.zone_is_required');
        }

        // Module validation
        $module = Module::find($dto->module_id);
        if (!$module) {
            $messages['module_id'] = translate('messages.module_not_found');
        } else {
            if ($dto->zone_id && !ModuleZone::where('module_id', $module->id)->where('zone_id', $dto->zone_id)->exists()) {
                $messages['module_id'] = translate('messages.module_not_available_in_selected_zone');
            }

            if ($module->module_type === 'rental' && addon_published_status('Rental') && empty($dto->pickup_zone_ids)) {
                $messages['pickup_zone_id'] = translate('messages.You_must_select_a_pickup_zone');
            }
        }

        // Subscription package validation
        if ($dto->business_plan === 'subscription-base') {
            if (empty($dto->package_id)) {
                $messages['package_id'] = translate('messages.You_must_select_a_package');
            } else {
                $packageType = ($module?->module_type === 'rental' && addon_published_status('Rental')) ? 'rental' : 'all';
                $package = SubscriptionPackage::where('status', 1)
                    ->where('module_type', $packageType)
                    ->find($dto->package_id);
                if (!$package) {
                    $messages['package_id'] = translate('messages.selected_package_is_invalid_for_module');
                }
            }
        }

        if (!empty($messages)) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Save or copy branding media for logo and cover photo.
     */
    protected function applyBrandingMedia(Store $store, VendorApplicationDTO $dto): void
    {
        $targetDisk = Helpers::getDisk();

        // 1. Logo
        if ($dto->logo instanceof UploadedFile) {
            $store->logo = Helpers::upload('store/', 'png', $dto->logo);
        } elseif (is_numeric($dto->logo) || is_string($dto->logo)) {
            $media = WhatsAppMedia::find($dto->logo);
            if ($media && $media->file_path && Storage::disk($media->storage_disk ?: 'public')->exists($media->file_path)) {
                $content = Storage::disk($media->storage_disk ?: 'public')->get($media->file_path);
                $ext = pathinfo($media->file_path, PATHINFO_EXTENSION) ?: 'png';
                $cleanBytes = $this->mediaPolicy->stripExif($content, $media->mime_type ?: 'image/png');
                $filename = $this->mediaPolicy->generateSafeFilename($ext, 'logo');
                Storage::disk($targetDisk)->put('store/' . $filename, $cleanBytes);
                $store->logo = $filename;
            }
        }
        if (!$store->logo) {
            $store->logo = 'def.png';
        }

        // 2. Cover Photo
        if ($dto->cover_photo instanceof UploadedFile) {
            $store->cover_photo = Helpers::upload('store/cover/', 'png', $dto->cover_photo);
        } elseif (is_numeric($dto->cover_photo) || is_string($dto->cover_photo)) {
            $media = WhatsAppMedia::find($dto->cover_photo);
            if ($media && $media->file_path && Storage::disk($media->storage_disk ?: 'public')->exists($media->file_path)) {
                $content = Storage::disk($media->storage_disk ?: 'public')->get($media->file_path);
                $ext = pathinfo($media->file_path, PATHINFO_EXTENSION) ?: 'png';
                $cleanBytes = $this->mediaPolicy->stripExif($content, $media->mime_type ?: 'image/png');
                $filename = $this->mediaPolicy->generateSafeFilename($ext, 'cover');
                Storage::disk($targetDisk)->put('store/cover/' . $filename, $cleanBytes);
                $store->cover_photo = $filename;
            }
        }
        if (!$store->cover_photo) {
            $store->cover_photo = 'def.png';
        }

        // 3. TIN / KYC Certificate
        if ($dto->tin_certificate_image instanceof UploadedFile) {
            $ext = $dto->tin_certificate_image->getClientOriginalExtension() ?: 'png';
            $store->tin_certificate_image = Helpers::upload('store/', $ext, $dto->tin_certificate_image);
        }
    }

    /**
     * Registration email dispatch.
     */
    protected function sendRegistrationEmails(Vendor $vendor, Store $store, ?Module $module): void
    {
        try {
            $admin = Admin::where('role_id', 1)->first();
            $fullName = $vendor->f_name . ' ' . $vendor->l_name;

            if ($module?->module_type !== 'rental' && config('mail.status') && Helpers::get_mail_status('registration_mail_status_store') == '1') {
                Mail::to($vendor->email)->send(new VendorSelfRegistration('pending', $fullName));
            } elseif ($module?->module_type === 'rental' && addon_published_status('Rental') && config('mail.status') && Helpers::get_mail_status('rental_registration_mail_status_provider') == '1') {
                Mail::to($vendor->email)->send(new ProviderSelfRegistration('pending', $fullName));
            }

            if ($module?->module_type !== 'rental' && config('mail.status') && Helpers::get_mail_status('store_registration_mail_status_admin') == '1') {
                Mail::to($admin?->getRawOriginal('email'))->send(new StoreRegistration('pending', $fullName));
            } elseif ($module?->module_type === 'rental' && addon_published_status('Rental') && config('mail.status') && Helpers::get_mail_status('rental_provider_registration_mail_status_admin') == '1') {
                Mail::to($admin?->getRawOriginal('email'))->send(new ProviderRegistration('pending', $fullName));
            }
        } catch (\Throwable $ex) {
            Log::info('Vendor registration mail notification failed: ' . $ex->getMessage());
        }
    }
}
