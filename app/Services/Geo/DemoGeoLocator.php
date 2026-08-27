<?php

namespace App\Services\Geo;

use App\Contracts\GeoLocator;

/**
 * Deterministic country lookup for demo data.
 *
 * Bound only while seeding. It exists so the demo exercises the real GeoIP
 * path -- reconciler asks a GeoLocator, caches the answer, skips the lookup
 * when the address has not changed -- rather than having the seeder write
 * the country column directly and leave that code untested.
 *
 * Deterministic on the address, so a site keeps its country across the
 * weeks of generated history instead of hopping continents each heartbeat.
 */
class DemoGeoLocator implements GeoLocator
{
    /** Weighted roughly like the WordPress install base. */
    protected const COUNTRIES = [
        'US' => 30, 'DE' => 10, 'GB' => 9, 'FR' => 7, 'IN' => 7, 'BR' => 6,
        'CA' => 5, 'NL' => 5, 'AU' => 4, 'ES' => 4, 'IT' => 4, 'JP' => 3,
        'PL' => 3, 'SE' => 3,
    ];

    public function country(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        // A small share resolve to nothing, because real lookups miss too --
        // and a chart with no gaps would set the wrong expectation.
        $roll = crc32($ip) % 100;

        if ($roll < 4) {
            return null;
        }

        $total = array_sum(self::COUNTRIES);
        $point = crc32(strrev($ip)) % $total;

        foreach (self::COUNTRIES as $code => $weight) {
            if (($point -= $weight) < 0) {
                return $code;
            }
        }

        return array_key_first(self::COUNTRIES);
    }
}
