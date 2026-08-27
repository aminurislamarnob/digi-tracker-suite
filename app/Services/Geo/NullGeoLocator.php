<?php

namespace App\Services\Geo;

use App\Contracts\GeoLocator;

/**
 * The default, and the one used in tests.
 *
 * Country is a nice-to-have, never a reason to fail ingest, so with no
 * database configured the platform simply records no country rather than
 * reaching out to some third-party lookup API on every heartbeat.
 */
class NullGeoLocator implements GeoLocator
{
    public function country(?string $ip): ?string
    {
        return null;
    }
}
