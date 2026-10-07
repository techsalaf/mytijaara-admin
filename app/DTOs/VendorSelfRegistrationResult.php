<?php

namespace App\DTOs;

use App\Models\Store;
use App\Models\Vendor;

final readonly class VendorSelfRegistrationResult
{
    public function __construct(
        public Vendor $vendor,
        public Store $store,
        public bool $subscriptionPaymentRequired,
    ) {}
}
