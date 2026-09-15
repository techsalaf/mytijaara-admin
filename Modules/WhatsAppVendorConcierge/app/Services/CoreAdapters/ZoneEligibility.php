<?php

namespace Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters;

use App\Models\Zone;
use MatanYadaev\EloquentSpatial\Objects\Point;

class ZoneEligibility
{
    public function contains(int $zoneId, float|string $latitude, float|string $longitude): bool
    {
        // Same spatial predicate as the host registration controller. Query errors
        // propagate: a missing spatial engine must never approve an unchecked pin.
        return Zone::query()
            ->whereContains('coordinates', new Point($latitude, $longitude, POINT_SRID))
            ->where('id', $zoneId)->exists();
    }
}
