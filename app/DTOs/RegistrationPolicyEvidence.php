<?php

namespace App\DTOs;

final readonly class RegistrationPolicyEvidence
{
    public function __construct(public string $locale, public string $termsVersion, public string $privacyVersion, public string $presentationHash) {}
}
