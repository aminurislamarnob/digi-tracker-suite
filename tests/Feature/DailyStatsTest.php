<?php

namespace Tests\Feature;

use App\Models\DailyStat;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * The rollup is where a wrong number becomes a wrong decision, because
 * every chart reads this table and nothing downstream re-checks it.
 */
class DailyStatsTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    /** Rolls up today, and returns today's row -- not whatever is newest. */
    protected function build(): DailyStat
    {
        $date = now()->toDateString();

        $this->artisan('telemetry:build-daily-stats', ['--date' => $date])->assertSuccessful();

        return DailyStat::acrossAccounts()
            ->where('project_id', $this->project->id)
            ->whereDate('date', $date)
            ->sole();
    }

    public function test_it_counts_installs_and_distributions(): void
    {
        $this->track($this->project, ['url' => 'https://one.com', 'admin_email' => 'a@one.com']);
        $this->track($this->project, [
            'url' => 'https://two.com',
            'admin_email' => 'b@two.com',
            'server' => ['php_version' => '8.2.19'],
        ]);

        $stat = $this->build();

        $this->assertSame(2, $stat->active_installs);
        $this->assertSame(2, $stat->new_installs);

        // Opt-in is the denominator that keeps the headline honest, so it
        // has to move in step with first-ever heartbeats.
        $this->assertSame(2, $stat->opted_in);

        $this->assertSame(['2.2.4' => 2], $stat->by_version);

        // 8.2.4 and 8.2.19 are the same answer to "can we drop PHP 8.1?".
        $this->assertSame(['8.2' => 2], $stat->by_php);

        $this->assertSame(['nginx' => 2], $stat->by_server);
        $this->assertSame(['no' => 2], $stat->by_multisite);
    }

    /**
     * A local install is somebody's laptop. Counting it as an active
     * install inflates the only number anyone will quote.
     */
    public function test_local_sites_are_excluded(): void
    {
        $this->track($this->project, ['url' => 'https://real.com', 'admin_email' => 'a@real.com']);
        $this->track($this->project, [
            'url' => 'https://dev.test',
            'admin_email' => 'b@dev.test',
            'is_local' => '1',
        ]);

        $this->assertSame(1, $this->build()->active_installs);
    }

    public function test_a_deactivated_site_stops_counting_and_a_returning_one_counts_again(): void
    {
        $this->track($this->project);
        $this->deactivate($this->project, ['reason_id' => 'other']);

        $stat = $this->build();

        $this->assertSame(0, $stat->active_installs);
        $this->assertSame(1, $stat->deactivations);
        $this->assertSame(0, $stat->reactivations);

        $this->travel(2)->days();
        $this->track($this->project);

        $stat = $this->build();

        $this->assertSame(1, $stat->active_installs);
        $this->assertSame(1, $stat->reactivations);
    }

    /**
     * Counts must come from history, not from sites.status -- that column
     * only ever holds today's answer, so a chart built from it would show
     * today's number on every date.
     */
    public function test_a_site_outside_the_window_is_not_active(): void
    {
        $this->track($this->project);

        $this->travel(Site::activeWindowDays() + 1)->days();

        $stat = $this->build();

        $this->assertSame(0, $stat->active_installs);
        $this->assertSame(0, $stat->new_installs);
    }

    public function test_rebuilding_a_day_is_idempotent(): void
    {
        $this->track($this->project);

        $first = $this->build();
        $second = $this->build();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->active_installs, $second->active_installs);
        $this->assertSame(1, DailyStat::acrossAccounts()->count());
    }

    public function test_refusals_are_counted_against_opt_ins(): void
    {
        $this->track($this->project);

        $this->post('/tracking-skipped', ['hash' => $this->project->hash]);
        $this->post('/tracking-skipped', ['hash' => $this->project->hash]);

        $stat = $this->build();

        $this->assertSame(1, $stat->opted_in);
        $this->assertSame(2, $stat->skipped);
    }

    public function test_each_account_is_rolled_up_separately(): void
    {
        $other = Project::factory()->create();

        $this->track($this->project, ['url' => 'https://mine.com', 'admin_email' => 'a@mine.com']);
        $this->track($other, ['url' => 'https://theirs.com', 'admin_email' => 'b@theirs.com']);

        $this->artisan('telemetry:build-daily-stats', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $stats = DailyStat::acrossAccounts()->get()->keyBy('project_id');

        $this->assertCount(2, $stats);
        $this->assertSame(1, $stats[$this->project->id]->active_installs);
        $this->assertSame($this->project->account_id, $stats[$this->project->id]->account_id);
        $this->assertSame($other->account_id, $stats[$other->id]->account_id);
    }
}
