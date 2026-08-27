<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

class ClassifyStaleSitesTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_a_site_that_stopped_reporting_becomes_inactive(): void
    {
        $this->track($this->project);

        $this->travel(Site::activeWindowDays() + 1)->days();

        $this->artisan('telemetry:classify-sites')->assertSuccessful();

        $this->assertSame(Site::STATUS_INACTIVE, Site::acrossAccounts()->sole()->status);
    }

    public function test_a_site_still_inside_the_window_is_left_alone(): void
    {
        $this->track($this->project);

        $this->travel(Site::activeWindowDays() - 1)->days();

        $this->artisan('telemetry:classify-sites')->assertSuccessful();

        $this->assertSame(Site::STATUS_ACTIVE, Site::acrossAccounts()->sole()->status);
    }

    /**
     * Silence and "I am leaving" are different signals -- one is probably a
     * broken wp-cron, the other is a decision. Demoting a deactivated site
     * to inactive would erase the churn signal it represents.
     */
    public function test_a_deactivated_site_is_never_reclassified(): void
    {
        $this->track($this->project);
        $this->deactivate($this->project, ['reason_id' => 'other']);

        $this->travel(Site::activeWindowDays() + 1)->days();

        $this->artisan('telemetry:classify-sites')->assertSuccessful();

        $this->assertSame(Site::STATUS_DEACTIVATED, Site::acrossAccounts()->sole()->status);
    }

    public function test_a_returning_site_is_promoted_back_by_its_next_heartbeat(): void
    {
        $this->track($this->project);

        $this->travel(Site::activeWindowDays() + 1)->days();
        $this->artisan('telemetry:classify-sites')->assertSuccessful();

        $this->track($this->project);

        $this->assertSame(Site::STATUS_ACTIVE, Site::acrossAccounts()->sole()->status);
    }
}
