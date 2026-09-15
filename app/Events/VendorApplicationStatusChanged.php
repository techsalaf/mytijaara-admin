<?php

namespace App\Events;

use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VendorApplicationStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $version;

    public function __construct(
        public Store $store,
        public Vendor $vendor,
        public string $status, // 'approved', 'denied', 'suspended', 'unsuspended'
        public ?string $rejectionNote = null,
        ?string $version = null
    ) {
        // An event identifies one transition, including repeated status cycles in
        // the same database timestamp second. Retries serialize this same ID.
        $this->version = $version ?? (string) \Illuminate\Support\Str::uuid();
    }
}
