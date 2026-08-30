<?php

namespace Tests\Feature\Panel;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AccountHeadlineStats;
use App\Filament\Widgets\AccountInstallsChart;
use App\Filament\Widgets\AccountProjectsTable;
use App\Filament\Widgets\ProjectsNeedingAttention;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Project;
use App\Models\RepoSnapshot;
use App\Models\User;
use App\Services\AccountOverview;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The account dashboard.
 *
 * The assertions worth having here are the refusals, because every one of
 * them has a plausible wrong answer that would be believed: nothing
 * captured rendering as zero downloads, a project with no rollup rendering
 * as zero installs, an opt-in rate with nothing to divide by rendering as
 * 0%. Each of those is a claim about the plugins rather than about us.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        // Linking a project queues its first capture -- see
        // Project::refreshRepositoryOnLink.
        Queue::fake();

        $this->account = Account::factory()->create(['name' => 'PluginizeLab']);

        $user = User::factory()->create();
        $user->accounts()->attach($this->account, ['role' => 'owner']);

        $this->actingAs($user);
        Filament::setTenant($this->account);
        CurrentAccount::set($this->account);
    }

    protected function project(array $attributes = []): Project
    {
        return Project::factory()->for($this->account)->create($attributes);
    }

    protected function stat(Project $project, array $attributes = []): DailyStat
    {
        return DailyStat::acrossAccounts()->create(array_merge([
            'account_id' => $this->account->id,
            'project_id' => $project->id,
            'date' => today(),
            'active_installs' => 100,
        ], $attributes));
    }

    protected function snapshot(Project $project, array $attributes = []): RepoSnapshot
    {
        return RepoSnapshot::acrossAccounts()->create(array_merge([
            'account_id' => $this->account->id,
            'project_id' => $project->id,
            'captured_on' => today(),
            'active_installs' => 1000,
            'downloaded' => 5000,
            'version' => '2.2.4',
        ], $attributes));
    }

    public function test_the_dashboard_renders(): void
    {
        $this->project();

        Livewire::test(Dashboard::class)->assertOk();
    }

    /** The heading is the account, not the word "Dashboard" a third time. */
    public function test_the_heading_is_the_account_name(): void
    {
        $this->assertSame('PluginizeLab', Livewire::test(Dashboard::class)->instance()->getHeading());
    }

    /* ---------------------------------------------------------------- */
    /* Headline figures */
    /* ---------------------------------------------------------------- */

    /**
     * Installs are a level, so the total is each project's own latest day.
     *
     * Summing everyone's figure on one shared date would silently drop any
     * project that has no row for that date, which on a dashboard looks
     * like installs falling off a cliff overnight.
     */
    public function test_installs_sum_each_projects_most_recent_day(): void
    {
        $fresh = $this->project();
        $this->stat($fresh, ['date' => today(), 'active_installs' => 100]);

        $quiet = $this->project();
        $this->stat($quiet, ['date' => today()->subDays(3), 'active_installs' => 40]);
        // An older row for the same project must not also be counted.
        $this->stat($quiet, ['date' => today()->subDays(9), 'active_installs' => 25]);

        $headline = app(AccountOverview::class)->headline();

        $this->assertSame(140, $headline['installs']['value']);
    }

    /**
     * Nothing captured is not the same as nothing downloaded.
     *
     * Zero here would be a claim about wordpress.org. The truth is a claim
     * about us, and it is that we have not looked yet.
     */
    public function test_downloads_are_null_rather_than_zero_before_any_capture(): void
    {
        $this->project(['wporg_slug' => 'never-captured']);

        $this->assertNull(app(AccountOverview::class)->headline()['downloads']['value']);
    }

    public function test_downloads_sum_the_latest_capture_per_project(): void
    {
        $one = $this->project(['wporg_slug' => 'one']);
        $this->snapshot($one, ['downloaded' => 4468]);
        // Yesterday's capture for the same project is superseded, not added.
        $this->snapshot($one, ['captured_on' => today()->subDay(), 'downloaded' => 4400]);

        $two = $this->project(['wporg_slug' => 'two']);
        $this->snapshot($two, ['downloaded' => 78203]);

        $this->assertSame(82671, app(AccountOverview::class)->headline()['downloads']['value']);
    }

    /** A first period has nothing to compare against, so it claims nothing. */
    public function test_a_first_period_reports_no_delta_rather_than_a_hundred_percent(): void
    {
        $project = $this->project();
        $this->stat($project, ['date' => today(), 'active_installs' => 100]);

        $this->assertNull(app(AccountOverview::class)->headline()['installs']['delta']);
    }

    public function test_the_stats_widget_renders_a_dash_for_an_unmeasured_figure(): void
    {
        $this->project(['wporg_slug' => 'never-captured']);

        Livewire::test(AccountHeadlineStats::class)
            ->assertOk()
            ->assertSee('—');
    }

    /* ---------------------------------------------------------------- */
    /* The series */
    /* ---------------------------------------------------------------- */

    public function test_the_series_totals_every_project_per_day(): void
    {
        $one = $this->project();
        $two = $this->project();

        $this->stat($one, ['date' => today(), 'active_installs' => 60, 'deactivations' => 2]);
        $this->stat($two, ['date' => today(), 'active_installs' => 40, 'deactivations' => 3]);

        $series = app(AccountOverview::class)->series();

        $this->assertCount(1, $series);
        $this->assertSame(100, $series->first()->active_installs);
        $this->assertSame(5, $series->first()->deactivations);
    }

    /**
     * A day the rollup never ran is a gap, not a zero.
     *
     * Zero-filling would draw a cliff down to nothing and back, which is a
     * story about the plugins rather than about the rollup.
     */
    public function test_a_missing_day_is_absent_rather_than_zero_filled(): void
    {
        $project = $this->project();

        $this->stat($project, ['date' => today()->subDays(2), 'active_installs' => 50]);
        $this->stat($project, ['date' => today(), 'active_installs' => 55]);

        $dates = app(AccountOverview::class)->series()->map(fn ($row) => $row->date->toDateString());

        $this->assertCount(2, $dates);
        $this->assertNotContains(today()->subDay()->toDateString(), $dates->all());
    }

    /** An empty axis reads as zero installs, so there is no axis at all. */
    public function test_the_chart_is_hidden_until_there_is_a_rollup(): void
    {
        $this->project();

        $this->assertFalse(AccountInstallsChart::canView());

        $this->stat($this->project());

        $this->assertTrue(AccountInstallsChart::canView());
    }

    /* ---------------------------------------------------------------- */
    /* Needs attention */
    /* ---------------------------------------------------------------- */

    /**
     * The state that started all this: linked to wordpress.org, captured by
     * nothing, so the repository page shows live totals over an empty chart.
     */
    public function test_a_linked_project_with_no_capture_is_flagged(): void
    {
        $project = $this->project(['name' => 'StoreSuite', 'wporg_slug' => 'storesuite']);
        $this->stat($project);

        $flags = app(AccountOverview::class)->attention();

        $this->assertCount(1, $flags);
        $this->assertSame('Never captured', $flags->first()['title']);
        $this->assertSame('danger', $flags->first()['colour']);
    }

    public function test_a_project_with_no_telemetry_is_flagged(): void
    {
        $this->project(['name' => 'Fresh']);

        $this->assertSame('No telemetry yet', app(AccountOverview::class)->attention()->first()['title']);
    }

    public function test_a_stale_rollup_is_flagged(): void
    {
        $project = $this->project();
        $this->stat($project, ['date' => today()->subDays(4)]);

        $this->assertSame('Rollup is stale', app(AccountOverview::class)->attention()->first()['title']);
    }

    /** A project measuring normally must not appear at all. */
    public function test_a_healthy_project_is_not_flagged(): void
    {
        $project = $this->project(['wporg_slug' => 'healthy']);
        $this->stat($project);
        $this->snapshot($project);

        $this->assertTrue(app(AccountOverview::class)->attention()->isEmpty());
    }

    /**
     * Nothing to say means nothing on screen. An all-clear panel is a thing
     * to scroll past every day for the sake of the rare day it speaks.
     */
    public function test_the_attention_widget_hides_itself_when_there_is_nothing_to_say(): void
    {
        $project = $this->project(['wporg_slug' => 'healthy']);
        $this->stat($project);
        $this->snapshot($project);

        $this->assertFalse(ProjectsNeedingAttention::canView());
    }

    public function test_the_attention_widget_lists_what_is_wrong(): void
    {
        $this->project(['name' => 'StoreSuite', 'wporg_slug' => 'storesuite']);

        Livewire::test(ProjectsNeedingAttention::class)
            ->assertOk()
            ->assertSee('StoreSuite')
            ->assertSee('Never captured');
    }

    /* ---------------------------------------------------------------- */
    /* The project table */
    /* ---------------------------------------------------------------- */

    public function test_the_table_puts_our_figure_beside_the_public_one(): void
    {
        $project = $this->project(['name' => 'Metadata Viewer', 'wporg_slug' => 'metadata-viewer']);
        $this->stat($project, ['active_installs' => 125]);
        $this->snapshot($project, ['active_installs' => 500, 'downloaded' => 4468]);

        $row = app(AccountOverview::class)->projects()->firstWhere('project.id', $project->id);

        $this->assertSame(125, $row['tracked']);
        $this->assertSame(500, $row['publicInstalls']);
        $this->assertSame(4468, $row['downloads']);
        $this->assertSame(25.0, $row['optInRate']);
    }

    /**
     * With either half missing there is no ratio at all, which is not the
     * same as a ratio of nothing.
     */
    public function test_an_unlinked_project_has_no_opt_in_rate(): void
    {
        $project = $this->project(['wporg_slug' => null]);
        $this->stat($project, ['active_installs' => 125]);

        $row = app(AccountOverview::class)->projects()->firstWhere('project.id', $project->id);

        $this->assertNull($row['optInRate']);
        $this->assertNull($row['publicInstalls']);
    }

    public function test_the_table_renders_and_names_every_project(): void
    {
        $this->project(['name' => 'Metadata Viewer']);
        $this->project(['name' => 'StoreSuite']);

        Livewire::test(AccountProjectsTable::class)
            ->assertOk()
            ->assertSee('Metadata Viewer')
            ->assertSee('StoreSuite');
    }

    /* ---------------------------------------------------------------- */
    /* Tenancy */
    /* ---------------------------------------------------------------- */

    /**
     * Asserted rather than assumed to follow from the global scope: this is
     * the one screen that aggregates across projects, so a scope that
     * failed here would blend two customers' numbers into one figure and
     * look entirely plausible doing it.
     */
    public function test_another_accounts_projects_are_not_counted(): void
    {
        $mine = $this->project();
        $this->stat($mine, ['active_installs' => 100]);

        $stranger = Project::factory()->for(Account::factory())->create();
        DailyStat::acrossAccounts()->create([
            'account_id' => $stranger->account_id,
            'project_id' => $stranger->id,
            'date' => today(),
            'active_installs' => 9999,
        ]);

        $overview = app(AccountOverview::class);

        $this->assertSame(100, $overview->headline()['installs']['value']);
        $this->assertCount(1, $overview->projects());
    }
}
