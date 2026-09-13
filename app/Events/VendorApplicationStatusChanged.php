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
        $timestamp = $vendor->updated_at?->getTimestamp() ?? time();
        $this->version = $version ?? hash('sha256', implode('|', [
            $vendor->id,
            $store->id,
            $status,
            $timestamp,
            (string) $rejectionNote,
        ]));
    }
}
