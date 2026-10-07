<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\CentralLogics\StoreLogic;
use App\DTOs\VendorSelfRegistrationInput;
use App\DTOs\VendorSelfRegistrationResult;
use App\Models\BusinessSetting;
use App\Models\Module;
use App\Models\ModuleZone;
use App\Models\Store;
use App\Models\SubscriptionPackage;
use App\Models\Translation;
use App\Models\Vendor;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MatanYadaev\EloquentSpatial\Objects\Point;

/** Owns new applications only. Channel-specific retries must resolve their saved result. */
class VendorSelfRegistrationService
{
    public function register(VendorSelfRegistrationInput $input): VendorSelfRegistrationResult
    {
        $files = [];
        $cleanup = function () use (&$files) {
            foreach ($files as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $error) {
                    Log::warning('Registration media cleanup failed', ['exception' => $error::class]);
                }
            }
        };
        // A channel may already own the outer transaction linking its application.
        if (DB::transactionLevel() > 0) {
            DB::connection()->afterRollBack($cleanup);
        }

        try {
            return DB::transaction(function () use ($input, &$files, $cleanup) {
                // Serialize canonical registrations before normalized identity checks. No historical identity backfill.
                BusinessSetting::where('key', 'toggle_store_registration')->lockForUpdate()->first();
                $module = $this->validate($input);
                if ($input->policyEvidence) {
                    app(RegistrationPolicyService::class)->verify($input->policyEvidence);
                } elseif (config('registration-policies.require_versions')) {
                    throw ValidationException::withMessages(['policy' => 'Explicit displayed policy versions are required.']);
                }
                DB::connection()->afterRollBack($cleanup);

                $vendor = new Vendor;
                $vendor->f_name = $input->firstName;
                $vendor->l_name = $input->lastName;
                $vendor->email = strtolower(trim($input->email));
                $vendor->phone = VendorSelfRegistrationInput::normalizePhone($input->phone);
                $vendor->password = $input->passwordHash;
                $vendor->status = null;
                $vendor->save();

                $store = new Store;
                $store->name = $input->names['default'];
                $store->address = $input->addresses['default'];
                $store->phone = $vendor->phone;
                $store->email = $vendor->email;
                $store->latitude = $input->latitude;
                $store->longitude = $input->longitude;
                $store->vendor_id = $vendor->id;
                $store->zone_id = $input->zoneId;
                $store->module_id = $input->moduleId;
                $store->pickup_zone_id = json_encode(array_values($input->pickupZoneIds), JSON_THROW_ON_ERROR);
                $store->tin = $input->tin;
                $store->tin_expire_date = $input->tinExpireDate;
                $store->logo = $this->upload($input->logo, 'store/', $files, $input->deferMediaPublication);
                $store->cover_photo = $this->upload($input->cover, 'store/cover/', $files, $input->deferMediaPublication);
                $store->tin_certificate_image = $input->tinCertificate
                    ? $this->upload($input->tinCertificate, 'store/', $files, $input->deferMediaPublication) : 'def.png';
                $store->delivery_time = $input->minimumDeliveryTime.'-'.$input->maximumDeliveryTime.' '.$input->deliveryTimeUnit;
                $subscription = $input->businessPlan === 'subscription-base';
                $store->store_business_model = $subscription ? 'none' : 'commission';
                $store->package_id = $subscription ? $input->packageId : null;
                $store->status = 0;
                $store->save();
                if ($input->deferMediaPublication) {
                    foreach ($files as [$disk,$path]) {
                        DB::table('vendor_registration_media')->insert(['store_id' => $store->id, 'directory' => dirname($path).'/', 'name' => basename($path), 'sha256' => hash_file('sha256', Storage::disk($disk)->path($path)), 'state' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }

                $this->translations($store, 'name', $input->names);
                $this->translations($store, 'address', $input->addresses);
                if (config('module.'.$module->module_type.'.always_open', false)) {
                    $scheduled = StoreLogic::insert_schedule($store->id);
                    if ($scheduled instanceof \Throwable) {
                        throw $scheduled;
                    }
                    if ($scheduled !== true) {
                        throw new \RuntimeException('Canonical store schedule creation failed.');
                    }
                }
                if ($input->policyEvidence) {
                    app(RegistrationPolicyService::class)->record($vendor->id, $store->id, $input->policyEvidence, $input->source);
                }
                $result = new VendorSelfRegistrationResult($vendor, $store, $subscription);
                if (! $input->deferMediaPublication) {
                    DB::afterCommit(function () use ($vendor, $module) {
                        // Notification failure never makes a persisted application appear uncreated.
                        try {
                            app(VendorRegistrationNotifier::class)->send($vendor, $module);
                        } catch (\Throwable $error) {
                            Log::warning('Registration notification preparation failed after commit', [
                                'vendor_id' => $vendor->id, 'exception' => $error::class,
                            ]);
                        }
                    });
                }

                return $result;
            });
        } catch (QueryException $error) {
            // Map only known vendor identity constraints, never leak SQL or identity values.
            $state = $error->errorInfo[0] ?? null;
            $detail = strtolower($error->errorInfo[2] ?? '');
            if (in_array($state, ['23000', '23505'], true)) {
                foreach (['email', 'phone'] as $field) {
                    if (str_contains($detail, 'vendors_'.$field.'_unique')
                        || str_contains($detail, 'unique constraint failed: vendors.'.$field)) {
                        throw ValidationException::withMessages([$field => 'This '.$field.' is already registered.']);
                    }
                }
            }
            throw $error;
        }
    }

    public function validatePreparedInput(VendorSelfRegistrationInput $input): void
    {
        $this->validate($input);
    }

    protected function validate(VendorSelfRegistrationInput $input): Module
    {
        validator([
            'f_name' => $input->firstName, 'l_name' => $input->lastName,
            'email' => $input->email, 'phone' => $input->phone,
            'name' => $input->names, 'address' => $input->addresses,
            'latitude' => $input->latitude, 'longitude' => $input->longitude,
            'zone_id' => $input->zoneId, 'module_id' => $input->moduleId,
            'minimum_delivery_time' => $input->minimumDeliveryTime,
            'maximum_delivery_time' => $input->maximumDeliveryTime,
            'delivery_time_type' => $input->deliveryTimeUnit,
            'business_plan' => $input->businessPlan, 'package_id' => $input->packageId,
            'logo' => $input->logo, 'cover_photo' => $input->cover,
            'tin_certificate_image' => $input->tinCertificate, 'tin_expire_date' => $input->tinExpireDate,
            'pickup_zone_id' => $input->pickupZoneIds,
            'terms_accepted' => $input->termsAccepted, 'privacy_accepted' => $input->privacyAccepted,
            'source' => $input->source,
        ], [
            'f_name' => 'required|string|max:100', 'l_name' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:vendors,email',
            'phone' => ['required', 'string', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'name' => 'required|array', 'name.default' => 'required|string|max:250', 'name.*' => 'nullable|string|max:250',
            'address' => 'required|array', 'address.default' => 'required|string', 'address.*' => 'nullable|string',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'zone_id' => 'required|integer', 'module_id' => 'required|integer',
            'minimum_delivery_time' => 'required|numeric|min:0',
            'maximum_delivery_time' => 'required|numeric|gte:minimum_delivery_time',
            'delivery_time_type' => 'required|in:min,hours,days',
            'business_plan' => 'required|in:commission-base,subscription-base',
            'package_id' => 'nullable|integer',
            'logo' => 'required|image|max:2048|mimes:'.IMAGE_FORMAT_FOR_VALIDATION,
            'cover_photo' => 'required|image|max:2048|mimes:'.IMAGE_FORMAT_FOR_VALIDATION,
            'tin_certificate_image' => 'nullable|file|max:2048|mimes:doc,pdf,jpg,jpeg,png',
            'tin_expire_date' => 'nullable|date', 'pickup_zone_id' => 'array',
            'pickup_zone_id.*' => 'integer|distinct|exists:zones,id,status,1',
            'terms_accepted' => 'accepted', 'privacy_accepted' => 'accepted',
            'source' => 'required|in:web,whatsapp,api',
        ])->validate();
        if (Vendor::whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($input->email))])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already registered.']);
        }
        $phone = VendorSelfRegistrationInput::normalizePhone($input->phone);
        validator(['phone' => $phone], ['phone' => 'required|digits_between:10,20'])->validate();
        // Include historical punctuation so normalization cannot bypass a pre-existing account.
        if (Vendor::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?", [$phone])->exists()) {
            throw ValidationException::withMessages(['phone' => 'This phone number is already registered.']);
        }
        if (empty($input->passwordHash) || empty(password_get_info($input->passwordHash)['algo'])) {
            throw ValidationException::withMessages(['password' => 'A prepared secure credential hash is required.']);
        }
        if (BusinessSetting::where('key', 'toggle_store_registration')->value('value') != '1') {
            throw ValidationException::withMessages(['registration' => translate('messages.store_self_registration_disabled')]);
        }
        $zone = Zone::active()->whereKey($input->zoneId)->lockForUpdate()->first();
        $module = Module::active()->notParcel()->notRideShare()->whereKey($input->moduleId)->lockForUpdate()->first();
        if (! $zone) {
            throw ValidationException::withMessages(['zone_id' => 'Select an active operating zone.']);
        }
        if (! $module || ! ModuleZone::where('module_id', $input->moduleId)->where('zone_id', $input->zoneId)->exists()) {
            throw ValidationException::withMessages(['module_id' => 'Select a business module available in this zone.']);
        }
        if (! $this->zoneContains($input->zoneId, (float) $input->latitude, (float) $input->longitude)) {
            throw ValidationException::withMessages(['zone' => translate('coordinates_out_of_zone')]);
        }
        if ($module->module_type === 'rental' && addon_published_status('Rental') && ! $input->pickupZoneIds) {
            throw ValidationException::withMessages(['pickup_zone_id' => translate('messages.You_must_select_a_pickup_zone')]);
        }
        // Read live configuration rather than Helpers' process-static settings cache.
        // Missing plan toggles retain the public registration's enabled default.
        $subscriptions = (BusinessSetting::where('key', 'subscription_business_model')->value('value') ?? '1') == '1';
        if ($input->businessPlan === 'subscription-base') {
            if (! $subscriptions) {
                throw ValidationException::withMessages(['business_plan' => 'Subscription registration is not available.']);
            }
            if (! $input->packageId || ! SubscriptionPackage::where('status', 1)
                ->where('module_type', Helpers::subscriptionPackageType($module))->whereKey($input->packageId)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['package_id' => translate('messages.You_must_select_a_package')]);
            }
        } elseif ($subscriptions && (BusinessSetting::where('key', 'commission_business_model')->value('value') ?? '1') != '1') {
            throw ValidationException::withMessages(['business_plan' => 'Commission registration is not available.']);
        }
        foreach (['logo' => $input->logo, 'cover_photo' => $input->cover] as $field => $file) {
            $this->validateFileContent($file, $field, true);
        }
        if ($input->tinCertificate) {
            $this->validateFileContent($input->tinCertificate, 'tin_certificate_image', false);
        }

        return $module;
    }

    protected function zoneContains(int $zoneId, float $latitude, float $longitude): bool
    {
        return Zone::whereKey($zoneId)->whereContains('coordinates', new Point($latitude, $longitude, POINT_SRID))->exists();
    }

    private function validateFileContent(UploadedFile $file, string $field, bool $image): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = $image ? ['jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp']
            : ['jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'doc' => 'application/msword'];
        if (filesize($file->getPathname()) > 2 * 1024 * 1024 || ($allowed[$extension] ?? null) !== $mime) {
            throw ValidationException::withMessages([$field => 'Upload a supported file whose content matches its extension, at most 2 MB.']);
        }
        if (str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($file->getPathname());
            if (! $dimensions || $dimensions[0] * $dimensions[1] > 25000000) {
                throw ValidationException::withMessages([$field => 'Upload a valid image, at most 25 megapixels.']);
            }
        }
    }

    private function upload(UploadedFile $file, string $directory, array &$files, bool $private = false): string
    {
        if ($private) {
            $name = bin2hex(random_bytes(24)).'.'.$file->getClientOriginalExtension();
            $disk = Storage::disk('registration_private');
            if (! app()->environment('testing')) {
                PrivateRegistrationStorageGuard::assertPrivate($disk->path(''));
            }
            if (! $disk->put($directory.$name, file_get_contents($file->getRealPath()), ['visibility' => 'private'])) {
                throw new \RuntimeException('Registration media preparation failed.');
            }
            $files[] = ['registration_private', $directory.$name];

            return $name;
        }
        $name = Helpers::upload($directory, $file->getClientOriginalExtension(), $file, 2);
        $files[] = [Helpers::getDisk(), $directory.$name];
        if (! Storage::disk(Helpers::getDisk())->exists($directory.$name)) {
            throw new \RuntimeException('Registration media was not stored.');
        }

        return $name;
    }

    /** Resume publication of an already committed pending application; never creates another vendor. */
    public function publishPreparedMedia(VendorSelfRegistrationResult $result): void
    {
        $rows = DB::table('vendor_registration_media')->where('store_id', $result->store->id)->orderBy('id')->get();
        if ($rows->count() < 2) {
            throw new \RuntimeException('Required registration media is missing.');
        }
        foreach ($rows as $row) {
            $path = $row->directory.$row->name;
            if ($row->state === 'published') {
                continue;
            }
            $private = Storage::disk('registration_private');
            $public = Storage::disk(Helpers::getDisk());
            if (! $private->exists($path) || ! hash_equals($row->sha256, hash_file('sha256', $private->path($path)))) {
                throw new \RuntimeException('Prepared media integrity failed.');
            }
            $stream = $private->readStream($path);
            try {
                if (! $public->put($path, $stream)) {
                    throw new \RuntimeException('Media publication failed.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            DB::table('vendor_registration_media')->where('id', $row->id)->update(['state' => 'published', 'updated_at' => now()]);
        }
    }

    private function translations(Store $store, string $key, array $values): void
    {
        $defaultLocale = str_replace('_', '-', app()->getLocale());
        foreach ($values as $locale => $value) {
            if ($locale === 'default') {
                continue;
            }
            if (! $value && $locale === $defaultLocale) {
                $value = $values['default'];
            }
            if ($value) {
                Translation::updateOrCreate([
                    'translationable_type' => Store::class, 'translationable_id' => $store->id,
                    'locale' => $locale, 'key' => $key,
                ], ['value' => $value]);
            }
        }
    }
}
