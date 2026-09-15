<?php

namespace Modules\WhatsAppVendorConcierge\app\DTOs;

use Illuminate\Http\UploadedFile;

class VendorApplicationDTO
{
    public function __construct(
        public string $f_name,
        public string $l_name,
        public string $phone,
        public string $email,
        public string $business_name,
        public string $address,
        public float|string $latitude,
        public float|string $longitude,
        public int $zone_id,
        public int $module_id,
        public ?string $password = null,
        public ?string $password_hash = null,
        public array $pickup_zone_ids = [],
        public string $minimum_delivery_time = '30',
        public string $maximum_delivery_time = '40',
        public string $delivery_time_type = 'min',
        public string $business_plan = 'commission-base',
        public ?int $package_id = null,
        public UploadedFile|string|null $logo = null,
        public UploadedFile|string|null $cover_photo = null,
        public ?string $tin = null,
        public ?string $tin_expire_date = null,
        public UploadedFile|string|null $tin_certificate_image = null,
        public ?string $cac_number = null,
        public ?string $nin = null,
        public ?int $kyc_media_id = null,
        public bool $terms_accepted = true,
        public bool $privacy_accepted = true,
        public string $source = 'web',
        public array $lang = ['default'],
        public ?string $operating_hours = null,
        public array $metadata = []
    ) {}

    public static function fromWebRequest(\Illuminate\Http\Request $request): self
    {
        $lang = $request->input('lang', ['default']);
        $defaultIdx = array_search('default', $lang);
        if ($defaultIdx === false) {
            $defaultIdx = 0;
        }

        $names = $request->input('name', []);
        $addresses = $request->input('address', []);

        $businessName = is_array($names) ? ($names[$defaultIdx] ?? reset($names) ?? '') : (string) $names;
        $address = is_array($addresses) ? ($addresses[$defaultIdx] ?? reset($addresses) ?? '') : (string) $addresses;

        $pickupZones = $request->input('pickup_zone_id', []);
        if (!is_array($pickupZones)) {
            $pickupZones = $pickupZones ? [(string) $pickupZones] : [];
        }

        return new self(
            f_name: (string) $request->input('f_name'),
            l_name: (string) $request->input('l_name', 'Owner'),
            phone: (string) $request->input('phone'),
            email: (string) $request->input('email'),
            business_name: $businessName,
            address: $address,
            latitude: $request->input('latitude'),
            longitude: $request->input('longitude'),
            zone_id: (int) $request->input('zone_id'),
            module_id: (int) $request->input('module_id'),
            password: $request->input('password'),
            pickup_zone_ids: $pickupZones,
            minimum_delivery_time: (string) $request->input('minimum_delivery_time', '30'),
            maximum_delivery_time: (string) $request->input('maximum_delivery_time', '40'),
            delivery_time_type: (string) $request->input('delivery_time_type', 'min'),
            business_plan: (string) $request->input('business_plan', 'commission-base'),
            package_id: $request->filled('package_id') ? (int) $request->input('package_id') : null,
            logo: $request->file('logo'),
            cover_photo: $request->file('cover_photo'),
            tin: $request->input('tin'),
            tin_expire_date: $request->input('tin_expire_date'),
            tin_certificate_image: $request->file('tin_certificate_image'),
            terms_accepted: true,
            privacy_accepted: true,
            source: 'web',
            lang: is_array($lang) ? $lang : ['default'],
            metadata: ['names' => $names, 'addresses' => $addresses],
        );
    }

    public static function fromWhatsAppSession(
        \Modules\WhatsAppVendorConcierge\app\Models\OnboardingSession $session,
        \Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact $contact
    ): self {
        $data = $session->collected_data ?? [];

        $phone = $data['phone'] ?? $contact->phone_number;
        $cleanPhone = preg_replace('/[^0-9]/', '', (string) $phone);

        $deliveryTime = $data['delivery_time'] ?? '30-40 min';
        $minDelivery = '30';
        $maxDelivery = '40';
        $deliveryUnit = 'min';
        if (preg_match('/^(\d+)-(\d+)\s*(min|hours|days)/i', $deliveryTime, $m)) {
            $minDelivery = $m[1];
            $maxDelivery = $m[2];
            $deliveryUnit = strtolower($m[3]);
        }

        $pickupZones = [];
        if (!empty($data['pickup_zone_id'])) {
            $pickupZones = is_array($data['pickup_zone_id']) ? $data['pickup_zone_id'] : [(string) $data['pickup_zone_id']];
        }

        return new self(
            f_name: $data['f_name'] ?? ($data['business_name'] ?? 'Vendor'),
            l_name: $data['l_name'] ?? 'Owner',
            phone: $cleanPhone,
            email: $data['email'] ?? '',
            business_name: $data['business_name'] ?? 'Store',
            address: $data['address'] ?? '',
            latitude: $data['latitude'] ?? 0,
            longitude: $data['longitude'] ?? 0,
            zone_id: (int) ($data['zone_id'] ?? 0),
            module_id: (int) ($data['module_id'] ?? 0),
            password_hash: $data['password_hash'] ?? null,
            pickup_zone_ids: $pickupZones,
            minimum_delivery_time: $minDelivery,
            maximum_delivery_time: $maxDelivery,
            delivery_time_type: $deliveryUnit,
            business_plan: $data['business_plan'] ?? 'commission-base',
            package_id: !empty($data['package_id']) ? (int) $data['package_id'] : null,
            logo: $data['logo_media_id'] ?? null,
            cover_photo: $data['cover_media_id'] ?? null,
            tin: $data['tin'] ?? ($data['cac_number'] ?? null),
            tin_expire_date: $data['tin_expire_date'] ?? null,
            tin_certificate_image: $data['tin_media_id'] ?? null,
            cac_number: $data['cac_number'] ?? null,
            nin: $data['nin'] ?? null,
            kyc_media_id: !empty($data['tin_media_id']) ? (int) $data['tin_media_id'] : null,
            terms_accepted: true,
            privacy_accepted: true,
            source: 'whatsapp',
            lang: ['default'],
            operating_hours: $data['operating_hours'] ?? null,
            metadata: [
                'session_id' => $session->id,
                'contact_id' => $contact->id,
                'kyc_type' => $data['kyc_type'] ?? null,
            ]
        );
    }
}
