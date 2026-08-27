<?php

namespace App\Services\Geo;

use App\Contracts\GeoLocator;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Throwable;

/**
 * Self-hosted GeoLite2-Country lookup.
 *
 * A local .mmdb file rather than a lookup API: an API would mean an
 * outbound request per heartbeat, a third party learning every one of our
 * users' IP addresses, and a dependency that can take ingest down with it.
 *
 * The reader is held open across a worker's lifetime because opening the
 * database is the expensive part, and it never fails loudly -- a stale or
 * missing file costs us a country code, not a payload.
 */
class MaxMindGeoLocator implements GeoLocator
{
    protected ?Reader $reader = null;

    protected bool $unavailable = false;

    public function __construct(protected string $databasePath) {}

    public function country(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $reader = $this->reader();

        if (! $reader) {
            return null;
        }

        try {
            return $reader->country($ip)->country->isoCode;
        } catch (AddressNotFoundException) {
            return null;
        } catch (Throwable) {
            // A corrupt database should degrade, not retry on every row.
            $this->unavailable = true;

            return null;
        }
    }

    protected function reader(): ?Reader
    {
        if ($this->unavailable) {
            return null;
        }

        if ($this->reader) {
            return $this->reader;
        }

        if (! is_readable($this->databasePath)) {
            $this->unavailable = true;

            return null;
        }

        try {
            return $this->reader = new Reader($this->databasePath);
        } catch (Throwable) {
            $this->unavailable = true;

            return null;
        }
    }
}
