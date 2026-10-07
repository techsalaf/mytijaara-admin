<?php

namespace App\DTOs;

use Illuminate\Http\UploadedFile;

/** Channel-neutral values. Names/addresses are keyed by locale, including default. */
final readonly class VendorSelfRegistrationInput
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public array $names,
        public array $addresses,
        public float|string|null $latitude,
        public float|string|null $longitude,
        public ?int $zoneId,
        public ?int $moduleId,
        public string $minimumDeliveryTime,
        public string $maximumDeliveryTime,
        public string $deliveryTimeUnit,
        public string $businessPlan,
        public ?int $packageId,
        public ?UploadedFile $logo,
        public ?UploadedFile $cover,
        public string $passwordHash,
        public bool $termsAccepted,
        public bool $privacyAccepted,
        public string $source,
        public array $pickupZoneIds = [],
        public ?string $tin = null,
        public ?string $tinExpireDate = null,
        public ?UploadedFile $tinCertificate = null,
        public ?RegistrationPolicyEvidence $policyEvidence = null,
        public bool $deferMediaPublication = false,
    ) {}

    /** This canonical system uses international digits; punctuation is presentation only. */
    public static function normalizePhone(string $phone): string
    {
        return str_replace([' ', '-', '+', '(', ')'], '', trim($phone));
    }
}
