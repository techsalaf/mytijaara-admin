<?php

namespace Modules\WhatsAppVendorConcierge\app\DTOs;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;

class VendorApplicationDTO
{
    public function __construct(
        public string $f_name,
        public string $l_name,
        public string $phone,
        public string $email,
        public string $business_name,
        public string $address,
        public float|string|null $latitude,
        public float|string|null $longitude,
        public ?int $zone_id,
        public ?int $module_id,
        public ?string $password = null,
        public ?string $password_hash = null,
        public array $pickup_zone_ids = [],
        public string $minimum_delivery_time = '',
        public string $maximum_delivery_time = '',
        public string $delivery_time_type = '',
        public string $business_plan = '',
        public ?int $package_id = null,
        public UploadedFile|string|null $logo = null,
        public UploadedFile|string|null $cover_photo = null,
        public ?string $tin = null,
        public ?string $tin_expire_date = null,
        public UploadedFile|string|null $tin_certificate_image = null,
        public ?string $cac_number = null,
        public ?string $nin = null,
        public ?int $kyc_media_id = null,
        public bool $terms_accepted = false,
        public bool $privacy_accepted = false,
        public string $source = 'web',
        public array $lang = ['default'],
        public ?string $operating_hours = null,
        public array $metadata = []
    ) {}

    public static function fromWebRequest(Request $request): self
    {
        $lang = $request->input('lang', ['default']);
        $defaultIdx = array_search('default', $lang);
        if ($defaultIdx === false) {
            throw ValidationException::withMessages(['lang' => 'Default language is required.']);
        }

        $names = $request->input('name', []);
        $addresses = $request->input('address', []);

        $businessName = is_array($names) ? ($names[$defaultIdx] ?? $names['default'] ?? '') : (string) $names;
        $address = is_array($addresses) ? ($addresses[$defaultIdx] ?? $addresses['default'] ?? '') : (string) $addresses;

        $pickupZones = $request->input('pickup_zone_id', []);
        if (! is_array($pickupZones)) {
            $pickupZones = $pickupZones ? [(string) $pickupZones] : [];
        }

        return new self(
            f_name: (string) $request->input('f_name'),
            l_name: (string) $request->input('l_name', ''),
            phone: (string) $request->input('phone'),
            email: (string) $request->input('email'),
            business_name: $businessName,
            address: $address,
            latitude: $request->input('latitude'),
            longitude: $request->input('longitude'),
            zone_id: $request->filled('zone_id') ? (int) $request->input('zone_id') : null,
            module_id: $request->filled('module_id') ? (int) $request->input('module_id') : null,
            password: $request->input('password'),
            pickup_zone_ids: $pickupZones,
            minimum_delivery_time: (string) $request->input('minimum_delivery_time', ''),
            maximum_delivery_time: (string) $request->input('maximum_delivery_time', ''),
            delivery_time_type: (string) $request->input('delivery_time_type', ''),
            business_plan: (string) $request->input('business_plan', ''),
            package_id: $request->filled('package_id') ? (int) $request->input('package_id') : null,
            logo: $request->file('logo'),
            cover_photo: $request->file('cover_photo'),
            tin: $request->input('tin'),
            tin_expire_date: $request->input('tin_expire_date'),
            tin_certificate_image: $request->file('tin_certificate_image'),
            terms_accepted: $request->boolean('terms_accepted'),
            privacy_accepted: $request->boolean('privacy_accepted'),
            source: 'web',
            lang: is_array($lang) ? $lang : ['default'],
            metadata: ['names' => $names, 'addresses' => $addresses],
        );
    }

    public static function fromWhatsAppSession(
        OnboardingSession $session,
        WhatsAppContact $contact
    ): self {
        $data = $session->collected_data ?? [];

        $phone = $data['phone'] ?? '';
        $minDelivery = (string) ($data['minimum_delivery_time'] ?? '');
        $maxDelivery = (string) ($data['maximum_delivery_time'] ?? '');
        $deliveryUnit = (string) ($data['delivery_time_type'] ?? '');
        if ($minDelivery === '' && $maxDelivery === '' && $deliveryUnit === ''
            && preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)\s+(min|hours|days)$/D', trim((string) ($data['delivery_time'] ?? '')), $m)) {
            [$unused, $minDelivery, $maxDelivery, $deliveryUnit] = $m;
        }

        $pickupZones = [];
        if (! empty($data['pickup_zone_id'])) {
            $pickupZones = is_array($data['pickup_zone_id']) ? $data['pickup_zone_id'] : [(string) $data['pickup_zone_id']];
        }

        return new self(
            f_name: $data['f_name'] ?? '',
            l_name: $data['l_name'] ?? '',
            phone: (string) $phone,
            email: $data['email'] ?? '',
            business_name: $data['business_name'] ?? '',
            address: $data['address'] ?? '',
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            zone_id: isset($data['zone_id']) ? (int) $data['zone_id'] : null,
            module_id: isset($data['module_id']) ? (int) $data['module_id'] : null,
            password_hash: $data['password_hash'] ?? null,
            pickup_zone_ids: $pickupZones,
            minimum_delivery_time: $minDelivery,
            maximum_delivery_time: $maxDelivery,
            delivery_time_type: $deliveryUnit,
            business_plan: $data['business_plan'] ?? '',
            package_id: ! empty($data['package_id']) ? (int) $data['package_id'] : null,
            logo: $data['logo_media_id'] ?? null,
            cover_photo: $data['cover_media_id'] ?? null,
            tin: $data['tin'] ?? null,
            tin_expire_date: $data['tin_expire_date'] ?? null,
            tin_certificate_image: $data['tin_media_id'] ?? null,
            cac_number: $data['cac_number'] ?? null,
            nin: $data['nin'] ?? null,
            kyc_media_id: ! empty($data['tin_media_id']) ? (int) $data['tin_media_id'] : null,
            terms_accepted: filter_var($data['terms_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            privacy_accepted: filter_var($data['privacy_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            source: 'whatsapp',
            lang: ['default'],
            operating_hours: $data['operating_hours'] ?? null,
            metadata: [
                'session_id' => $session->id,
                'contact_id' => $contact->id,
                'kyc_type' => $data['kyc_type'] ?? null,
                'names' => $data['names'] ?? ['default' => $data['business_name'] ?? ''],
                'addresses' => $data['addresses'] ?? ['default' => $data['address'] ?? ''],
            ]
        );
    }
}
