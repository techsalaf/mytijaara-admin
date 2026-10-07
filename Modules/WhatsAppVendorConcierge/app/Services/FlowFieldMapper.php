<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\DTOs\VendorSelfRegistrationInput;
use App\DTOs\RegistrationPolicyEvidence;
use Illuminate\Support\Facades\Hash;
use Modules\WhatsAppVendorConcierge\app\Models\VendorFlowSession;

class FlowFieldMapper
{
    public const FIELDS = ['first_name', 'surname', 'email', 'store_name', 'module_id', 'address', 'latitude', 'longitude', 'zone_id', 'minimum_delivery_time', 'maximum_delivery_time', 'delivery_time_unit', 'pickup_zone_ids', 'business_plan', 'package_id', 'tin', 'tin_expire_date', 'terms_agreed', 'privacy_acknowledged', 'terms_version', 'privacy_version', 'presentation_hash'];

    public function allowlist(array $values): array
    {
        $out = [];
        foreach (array_intersect_key($values, array_flip(self::FIELDS)) as $field => $value) {
            if (in_array($field, ['terms_agreed', 'privacy_acknowledged'], true)) {
                if (is_bool($value)) {
                    $out[$field] = $value;
                }
            } elseif ($field === 'pickup_zone_ids') {
                if (is_array($value) && count($value) <= 20 && ! array_filter($value, fn ($id) => ! is_string($id) && ! is_int($id))) {
                    $out[$field] = array_values(array_map('strval', $value));
                }
            } elseif (is_string($value) || is_int($value) || is_float($value)) {
                if (strlen((string) $value) <= 2048) {
                    $out[$field] = (string) $value;
                }
            }
        }

        return $out;
    }

    public function input(VendorFlowSession $session, array $values): VendorSelfRegistrationInput
    {
        $v = $this->allowlist($values);
        $media = app(FlowMediaService::class);
        validator($v, ['first_name' => 'required|string|max:100', 'surname' => 'required|string|max:100', 'email' => 'required|email|max:100', 'store_name' => 'required|string|max:250', 'address' => 'required|string|max:500',
            'module_id' => 'required|integer', 'zone_id' => 'required|integer', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'minimum_delivery_time' => 'required|numeric|min:0', 'maximum_delivery_time' => 'required|numeric|gte:minimum_delivery_time', 'delivery_time_unit' => 'required|in:min,hours,days',
            'business_plan' => 'required|in:commission-base,subscription-base', 'package_id' => 'nullable|integer', 'pickup_zone_ids' => 'array', 'pickup_zone_ids.*' => 'integer|distinct',
            'terms_agreed' => 'required|boolean|accepted', 'privacy_acknowledged' => 'required|boolean|accepted', 'terms_version' => 'required|string', 'privacy_version' => 'required|string', 'presentation_hash' => 'required|string|size:64',
            'tin' => 'nullable|string|max:100', 'tin_expire_date' => 'nullable|date'])->validate();

        return new VendorSelfRegistrationInput(firstName: $v['first_name'], lastName: $v['surname'], email: strtolower(trim($v['email'])), phone: $session->sender,
            names: ['default' => $v['store_name'], $session->locale => $v['store_name']], addresses: ['default' => $v['address'], $session->locale => $v['address']],
            latitude: $v['latitude'], longitude: $v['longitude'], zoneId: (int) $v['zone_id'], moduleId: (int) $v['module_id'],
            minimumDeliveryTime: (string) $v['minimum_delivery_time'], maximumDeliveryTime: (string) $v['maximum_delivery_time'], deliveryTimeUnit: $v['delivery_time_unit'], businessPlan: $v['business_plan'],
            packageId: isset($v['package_id']) && $v['package_id'] !== '' ? (int) $v['package_id'] : null, logo: $media->file($session, 'logo'), cover: $media->file($session, 'cover'),
            passwordHash: Hash::make(bin2hex(random_bytes(64))), termsAccepted: $v['terms_agreed'] === true, privacyAccepted: $v['privacy_acknowledged'] === true, source: 'whatsapp',
            pickupZoneIds: array_map('intval', $v['pickup_zone_ids'] ?? []), tin: $v['tin'] ?? null, tinExpireDate: ($v['tin_expire_date'] ?? '') ?: null, tinCertificate: $media->file($session, 'tin_document'),
            policyEvidence: new RegistrationPolicyEvidence($session->locale, $v['terms_version'], $v['privacy_version'], $v['presentation_hash']), deferMediaPublication: true);
    }
}
