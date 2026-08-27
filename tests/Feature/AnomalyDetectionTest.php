<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\RawPayload;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ingest has no authentication -- the protocol has none, and the hash that
 * routes a payload to an account travels as a plain body field visible in
 * GPL source. Anyone can inflate a project's install count. The only
 * honest posture is to notice when someone does.
 */
class AnomalyDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();

        config([
            'telemetry.anomaly.new_sites_per_hour' => 3,
            'telemetry.anomaly.payloads_per_hour' => 5,
            'telemetry.anomaly.payloads_per_ip_per_hour' => 4,
        ]);
    }

    protected function seedSites(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Site::acrossAccounts()->create([
                'account_id' => $this->project->account_id,
                'project_id' => $this->project->id,
                'site_key' => sha1("site-{$i}"),
                'url' => "https://site-{$i}.com",
                'canonical_url' => "site-{$i}.com",
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }
    }

    public function test_quiet_traffic_reports_nothing(): void
    {
        $this->seedSites(2);

        $this->artisan('telemetry:detect-anomalies')
            ->expectsOutputToContain('No anomalies')
            ->assertSuccessful();
    }

    /**
     * The signal that matters most. A real project gains installs at the
     * pace wordpress.org serves downloads; a burst of brand-new sites is
     * somebody generating URLs.
     */
    public function test_a_burst_of_new_sites_is_flagged(): void
    {
        $this->seedSites(10);

        $this->artisan('telemetry:detect-anomalies')
            ->expectsOutputToContain('10 new sites')
            ->assertSuccessful();
    }

    public function test_one_address_speaking_for_a_crowd_is_flagged(): void
    {
        for ($i = 0; $i < 6; $i++) {
            RawPayload::acrossAccounts()->create([
                'account_id' => $this->project->account_id,
                'project_id' => $this->project->id,
                'route' => RawPayload::ROUTE_TRACK,
                'payload' => [],
                'ip' => '198.51.100.7',
            ]);
        }

        $this->artisan('telemetry:detect-anomalies')
            ->expectsOutputToContain('198.51.100.7')
            ->assertSuccessful();
    }

    /**
     * It alerts, it does not block. A genuine viral week and an attack look
     * identical from here, and silently discarding real installs would be
     * the worse failure.
     */
    public function test_detection_never_discards_data(): void
    {
        $this->seedSites(10);

        $this->artisan('telemetry:detect-anomalies')->assertSuccessful();

        $this->assertSame(10, Site::acrossAccounts()->count());
    }
}
