<?php

namespace App\Contracts;

/**
 * Turns a request IP into a country.
 *
 * Deliberately an interface with a do-nothing default. The SDK fork drops
 * upstream's icanhazip.com call -- an undisclosed third-party request made
 * from the customer's server -- so country is derived here instead, from
 * an IP we already have. Nothing else about the visitor is resolved: city
 * and coordinates are not needed to answer any question this platform
 * asks, and collecting them anyway would be the wrong default.
 */
interface GeoLocator
{
    /** ISO 3166-1 alpha-2, or null when it cannot be determined. */
    public function country(?string $ip): ?string;
}
