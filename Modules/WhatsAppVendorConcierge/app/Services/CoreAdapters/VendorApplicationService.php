<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\DTOs\VendorSelfRegistrationInput;
use App\Services\VendorSelfRegistrationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\DTOs\VendorApplicationDTO;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMessage;

/** Compatibility port: maps channel media and values; core owns all domain writes. */
class VendorApplicationService
{
    public function __construct(protected VendorSelfRegistrationService $registration) {}

    public function submit(VendorApplicationDTO $dto): array
    {
        if ($dto->source === 'whatsapp' && $dto->password !== null) {
            throw ValidationException::withMessages(['password' => 'Use the secure credential page.']);
        }
        $hash = $dto->password_hash;
        if ($dto->source !== 'whatsapp' && $dto->password !== null) {
            validator(['password' => $dto->password], [
                'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            ])->validate();
            $hash = bcrypt($dto->password);
        }
        if (! $hash || empty(password_get_info($hash)['algo'])) {
            throw ValidationException::withMessages(['password' => 'Complete secure password setup before submitting.']);
        }
        $temporary = [];
        try {
            $result = $this->registration->register(new VendorSelfRegistrationInput(
                firstName: $dto->f_name, lastName: $dto->l_name, email: $dto->email, phone: $dto->phone,
                names: $this->localized($dto, 'names', $dto->business_name),
                addresses: $this->localized($dto, 'addresses', $dto->address),
                latitude: $dto->latitude, longitude: $dto->longitude,
                zoneId: $dto->zone_id, moduleId: $dto->module_id,
                minimumDeliveryTime: $dto->minimum_delivery_time, maximumDeliveryTime: $dto->maximum_delivery_time,
                deliveryTimeUnit: $dto->delivery_time_type, businessPlan: $dto->business_plan,
                packageId: $dto->package_id,
                logo: $this->file($dto->logo, 'logo', $dto, $temporary),
                cover: $this->file($dto->cover_photo, 'cover_photo', $dto, $temporary),
                passwordHash: $hash, termsAccepted: $dto->terms_accepted, privacyAccepted: $dto->privacy_accepted,
                source: $dto->source, pickupZoneIds: $dto->pickup_zone_ids,
                tin: $dto->tin, tinExpireDate: $dto->tin_expire_date,
                tinCertificate: $this->file($dto->tin_certificate_image, 'tin_certificate_image', $dto, $temporary),
            ));

            return ['vendor' => $result->vendor, 'store' => $result->store];
        } finally {
            foreach ($temporary as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function localized(VendorApplicationDTO $dto, string $key, string $default): array
    {
        $values = $dto->metadata[$key] ?? null;
        if ($values === null) {
            return ['default' => $default];
        }
        if (array_key_exists('default', $values)) {
            return $values;
        }
        if (count($values) !== count($dto->lang) || ! in_array('default', $dto->lang, true)) {
            throw ValidationException::withMessages(['lang' => 'Supply matching translated fields including default.']);
        }

        return array_combine($dto->lang, $values);
    }

    /** Materialize processed, privately staged media into the canonical file contract. */
    private function file(UploadedFile|string|null $value, string $field, VendorApplicationDTO $dto, array &$temporary): ?UploadedFile
    {
        if ($value instanceof UploadedFile || $value === null) {
            return $value;
        }
        $media = ctype_digit($value) ? WhatsAppMedia::find($value) : null;
        $contactId = $dto->metadata['contact_id'] ?? null;
        $sessionId = $dto->metadata['session_id'] ?? null;
        $owned = $media && $contactId && $sessionId && WhatsAppMessage::where('media_id', $media->id)
            ->where('direction', 'inbound')
            ->where('metadata->registration_session_id', $sessionId)
            ->where('metadata->registration_contact_id', $contactId)
            ->whereHas('conversation', fn ($query) => $query
                ->where('contact_id', $contactId)->where('onboarding_session_id', $sessionId))->exists();
        if (! $owned || $media->status !== 'processed' || ! $media->file_path || $media->storage_disk !== 'local'
            || ! Storage::disk('local')->exists($media->file_path) || ($media->expires_at && $media->expires_at->isPast())) {
            throw ValidationException::withMessages([$field => 'Upload a valid file for this application and wait for processing.']);
        }
        $disk = Storage::disk('local');
        if ($disk->size($media->file_path) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([$field => 'Files must be at most 2 MB.']);
        }
        $path = tempnam(sys_get_temp_dir(), 'vendor-registration-');
        if ($path === false) {
            throw new \RuntimeException('Unable to prepare registration media.');
        }
        $temporary[] = $path;
        $bytes = $disk->get($media->file_path);
        if (strlen($bytes) > 2 * 1024 * 1024 || file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw ValidationException::withMessages([$field => 'Unable to prepare the complete upload.']);
        }

        return new UploadedFile($path, basename($media->file_path), null, null, true);
    }
}
