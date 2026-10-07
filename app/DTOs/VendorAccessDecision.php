<?php

namespace App\DTOs;

final readonly class VendorAccessDecision
{
    public function __construct(
        public bool $eligible,
        public string $reason,
        public string $messageCategory,
        public bool $preActivationAllowed = false,
    ) {}
}
