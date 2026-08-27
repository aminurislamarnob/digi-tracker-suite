<?php

namespace Tests\Feature\Ingest;

use App\Models\Deactivation;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

class DeactivateTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_a_deactivation_is_recorded_and_flips_the_site(): void
    {
        $this->track($this->project)->assertOk();

        $this->deactivate($this->project, [
            'reason_id' => 'found-better-plugin',
            'reason_info' => 'Switched to something with REST support.',
        ])->assertOk();

        $deactivation = Deactivation::acrossAccounts()->sole();

        $this->assertSame('found-better-plugin', $deactivation->reason_id);
        $this->assertSame('Switched to something with REST support.', $deactivation->reason_info);
        $this->assertSame('2.2.4', $deactivation->project_version);

        // Theme is copied in, not joined: "which themes do we churn from"
        // is a question about the site as it was at that moment.
        $this->assertSame('twentytwentyfive', $deactivation->theme_slug);

        $site = Site::acrossAccounts()->sole();

        $this->assertSame(Site::STATUS_DEACTIVATED, $site->status);
        $this->assertNotNull($site->deactivated_at);
    }

    /**
     * The reason list is only unique per project, so a stock reason_id must
     * resolve through the project that received it -- never globally.
     */
    public function test_the_reason_label_comes_from_the_projects_own_list(): void
    {
        $this->deactivate($this->project, ['reason_id' => 'is-not-working'])->assertOk();

        $this->assertSame('Not working', Deactivation::acrossAccounts()->sole()->reasonLabel());
    }

    /**
     * Dismissing the dialog without choosing sends the literal 'none'.
     * Losing the churn event because the feedback was blank would be the
     * wrong trade: the event matters more than the reason.
     */
    public function test_a_deactivation_with_no_reason_is_still_recorded(): void
    {
        $payload = $this->payload($this->project, ['reason_id' => 'none', 'reason_info' => '']);

        $this->postTelemetry('/deactivate', $payload)->assertOk();

        $deactivation = Deactivation::acrossAccounts()->sole();

        $this->assertSame('none', $deactivation->reason_id);
        $this->assertNull($deactivation->reason_info);
        $this->assertSame(Site::STATUS_DEACTIVATED, Site::acrossAccounts()->sole()->status);
    }

    /**
     * A deactivation carries the full insights payload, and it is the last
     * environment snapshot we will ever get from that site.
     */
    public function test_a_deactivation_still_records_an_environment_report(): void
    {
        $this->deactivate($this->project, ['reason_id' => 'other'])->assertOk();

        $this->assertSame('8.2.4', SiteReport::acrossAccounts()->sole()->php_version);
    }

    public function test_a_heartbeat_after_a_deactivation_is_a_reactivation(): void
    {
        $this->track($this->project)->assertOk();
        $this->deactivate($this->project, ['reason_id' => 'other'])->assertOk();

        $this->travel(2)->days();

        $this->track($this->project)->assertOk();

        $site = Site::acrossAccounts()->sole();

        $this->assertSame(Site::STATUS_ACTIVE, $site->status);
        $this->assertNull($site->deactivated_at);

        // The churn event is never erased -- it is closed, so the rollup can
        // still answer "how many left, and how many came back, and when".
        $deactivation = Deactivation::acrossAccounts()->sole();

        $this->assertNotNull($deactivation->reactivated_at);
    }

    public function test_leaving_twice_records_two_events(): void
    {
        $this->deactivate($this->project, ['reason_id' => 'is-not-working'])->assertOk();

        $this->travel(2)->days();
        $this->track($this->project)->assertOk();

        $this->travel(2)->days();
        $this->deactivate($this->project, ['reason_id' => 'found-better-plugin'])->assertOk();

        $this->assertSame(2, Deactivation::acrossAccounts()->count());
        $this->assertSame(1, Deactivation::acrossAccounts()->whereNull('reactivated_at')->count());
    }

    public function test_an_unknown_hash_is_rejected(): void
    {
        $this->deactivate($this->project, ['hash' => '00000000-0000-4000-8000-000000000000'])
            ->assertNotFound();

        $this->assertDatabaseCount('deactivations', 0);
    }
}
