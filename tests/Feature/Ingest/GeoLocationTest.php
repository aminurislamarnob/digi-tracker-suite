<?php

namespace Tests\Feature\Ingest;

use App\Contracts\GeoLocator;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * Country is derived here, from an IP we already have, because the SDK
 * fork drops upstream's icanhazip.com call -- an undisclosed third-party
 * request made from the customer's own server on every heartbeat.
 */
class GeoLocationTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    protected function locateAs(?string $country): void
    {
        $this->app->instance(GeoLocator::class, new class($country) implements GeoLocator
        {
            public function __construct(protected ?string $country) {}

            public function country(?string $ip): ?string
            {
                return $this->country;
            }
        });
    }

    public function test_the_country_is_resolved_from_the_observed_ip(): void
    {
        $this->locateAs('GB');

        $this->track($this->project)->assertOk();

        $this->assertSame('GB', Site::acrossAccounts()->sole()->country);
    }

    /**
     * With no database configured the platform records no country rather
     * than calling some lookup API on every heartbeat. A missing country
     * is never a reason to fail ingest.
     */
    public function test_ingest_succeeds_when_no_country_can_be_determined(): void
    {
        $this->locateAs(null);

        $this->track($this->project)->assertOk();

        $site = Site::acrossAccounts()->sole();

        $this->assertNull($site->country);
        $this->assertSame(Site::STATUS_ACTIVE, $site->status);
    }

    /**
     * The lookup is the expensive part, and the answer only changes when
     * the address does -- a weekly heartbeat from a static IP must not pay
     * for it every time.
     */
    public function test_a_known_country_is_not_looked_up_again(): void
    {
        $this->locateAs('GB');
        $this->track($this->project)->assertOk();

        $this->travel(7)->days();

        // If this locator were consulted the country would change.
        $this->locateAs('ZZ');
        $this->track($this->project)->assertOk();

        $this->assertSame('GB', Site::acrossAccounts()->sole()->country);
    }
}
