<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Projects\Pages\ProjectRepository;
use App\Jobs\RefreshRepoStats;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Project;
use App\Models\RepoDownload;
use App\Models\RepoKeyword;
use App\Models\RepoRanking;
use App\Models\RepoRelease;
use App\Models\RepoSnapshot;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\FakesWordPressOrg;
use Tests\TestCase;

/**
 * The page that puts the public record beside ours.
 *
 * Most of what is asserted here is about refusals to state things we do
 * not know: an opt-in rate with no public figure to divide by, a release
 * that has not reached half of installs, a keyword outside the search
 * window. Each of those has a plausible-looking wrong answer -- 0%, 0 days,
 * rank 999 -- and each would be believed.
 */
class ProjectRepositoryPageTest extends TestCase
{
    use FakesWordPressOrg, RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Linking a project queues its first capture -- see
         * Project::refreshRepositoryOnLink. Faked before the project is
         * created, or the sync queue runs a real fetch in setUp.
         */
        Queue::fake();

        // The page reads the download summary live, so every render needs
        // this or TestCase's stray-request guard refuses the call.
        $this->fakeDownloadSummary();

        $this->account = Account::factory()->create();
        $this->project = Project::factory()->for($this->account)->create([
            'slug' => 'metadata-viewer',
            'wporg_slug' => 'metadata-viewer',
        ]);

        $user = User::factory()->create();
        $user->accounts()->attach($this->account, ['role' => 'owner']);

        $this->actingAs($user);
        Filament::setTenant($this->account);
        CurrentAccount::set($this->account);

        /*
         * Creating the project above claimed the refresh cooldown, which is
         * the interlock working -- linking and then pressing the button is
         * meant to be one fetch. Released here so the button tests below
         * exercise the button rather than that interlock, which has its own
         * test.
         */
        Cache::forget(RefreshRepoStats::cooldownKey($this->project->id));
    }

    protected function page(): Testable
    {
        return Livewire::test(ProjectRepository::class, ['record' => $this->project->slug]);
    }

    protected function snapshot(array $attributes = []): RepoSnapshot
    {
        return RepoSnapshot::acrossAccounts()->create(array_merge([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'captured_on' => today(),
            'active_installs' => 500,
            'rating' => 100,
            'num_ratings' => 12,
            'support_threads' => 10,
            'support_threads_resolved' => 7,
            'version' => '2.2.4',
            'version_distribution' => ['2.2' => 71.5, '2.1' => 20.0, 'other' => 8.5],
        ], $attributes));
    }

    public function test_the_page_renders(): void
    {
        $this->snapshot();

        $this->page()->assertOk();
    }

    public function test_the_opt_in_rate_is_tracked_over_public(): void
    {
        $this->snapshot(['active_installs' => 500]);

        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 125,
        ]);

        $headline = $this->page()->instance()->getHeadline();

        $this->assertSame(500, $headline['publicInstalls']);
        $this->assertSame(125, $headline['tracked']);
        $this->assertSame(25.0, $headline['optInRate']);
    }

    /**
     * The refusal that keeps the number honest. Zero would claim nobody
     * opted in, when the truth is we have nothing to compare against.
     */
    public function test_no_snapshot_means_no_opt_in_rate_rather_than_zero(): void
    {
        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 125,
        ]);

        $this->assertNull($this->page()->instance()->getHeadline()['optInRate']);
    }

    /**
     * The repository reports minor lines; telemetry reports exact versions.
     * Without folding, every row would appear on one side and be missing
     * from the other.
     */
    public function test_our_exact_versions_fold_to_the_repositorys_minor_lines(): void
    {
        $this->snapshot();

        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 100,
            'by_version' => ['2.2.4' => 60, '2.2.3' => 20, '2.1.0' => 20],
        ]);

        $rows = collect($this->page()->instance()->getVersionComparison())->keyBy('version');

        // 2.2.4 and 2.2.3 collapse into one 2.2 line worth 80%.
        $this->assertSame(80.0, $rows['2.2']['ourShare']);
        $this->assertSame(71.5, $rows['2.2']['publicShare']);
        $this->assertSame(20.0, $rows['2.1']['ourShare']);

        // "other" is the repository's bucket, not a version anyone runs.
        $this->assertArrayNotHasKey('other', $rows->all());
    }

    public function test_a_version_only_one_side_knows_about_still_appears(): void
    {
        $this->snapshot(['version_distribution' => ['3.0' => 100.0]]);

        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 10,
            'by_version' => ['2.2.4' => 10],
        ]);

        $rows = collect($this->page()->instance()->getVersionComparison())->keyBy('version');

        $this->assertNull($rows['3.0']['ourShare']);
        $this->assertNull($rows['2.2']['publicShare']);
    }

    public function test_days_to_half_adoption_is_measured_from_the_release_date(): void
    {
        RepoRelease::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'version' => '2.2.4',
            'released_on' => today()->subDays(10),
            'source' => RepoRelease::FROM_SVN,
        ]);

        // Under half four days in, over half six days in.
        foreach ([4 => 30, 6 => 55] as $daysAfter => $share) {
            DailyStat::acrossAccounts()->create([
                'account_id' => $this->account->id,
                'project_id' => $this->project->id,
                'date' => today()->subDays(10 - $daysAfter),
                'active_installs' => 100,
                'by_version' => ['2.2.4' => $share, '2.2.3' => 100 - $share],
            ]);
        }

        $releases = collect($this->page()->instance()->getReleases())->keyBy('version');

        $this->assertSame(6, $releases['2.2.4']['daysToHalf']);
    }

    /** Not there yet is not the same as adopted instantly. */
    public function test_a_version_below_the_threshold_reports_no_figure(): void
    {
        RepoRelease::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'version' => '2.2.4',
            'released_on' => today()->subDays(5),
            'source' => RepoRelease::FROM_SVN,
        ]);

        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 100,
            'by_version' => ['2.2.4' => 10, '2.2.3' => 90],
        ]);

        $releases = collect($this->page()->instance()->getReleases())->keyBy('version');

        $this->assertNull($releases['2.2.4']['daysToHalf']);
    }

    public function test_a_weekly_ranking_move_is_reported(): void
    {
        $keyword = RepoKeyword::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'keyword' => 'metadata viewer',
        ]);

        foreach ([['days' => 8, 'position' => 12], ['days' => 0, 'position' => 4]] as $row) {
            RepoRanking::acrossAccounts()->create([
                'account_id' => $this->account->id,
                'project_id' => $this->project->id,
                'repo_keyword_id' => $keyword->id,
                'captured_on' => today()->subDays($row['days']),
                'position' => $row['position'],
                'searched_depth' => 100,
                'total_results' => 514,
            ]);
        }

        $rankings = collect($this->page()->instance()->getRankings())->keyBy('keyword');

        $this->assertSame(4, $rankings['metadata viewer']['position']);
        // Twelfth to fourth is eight places better, expressed as a gain.
        $this->assertSame(8, $rankings['metadata viewer']['movement']);
    }

    /**
     * Entering or leaving the search window is a change of kind, not of
     * degree. Subtracting against a null would invent a movement.
     */
    public function test_entering_the_window_reports_no_movement_rather_than_a_number(): void
    {
        $keyword = RepoKeyword::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'keyword' => 'metadata viewer',
        ]);

        foreach ([['days' => 8, 'position' => null], ['days' => 0, 'position' => 4]] as $row) {
            RepoRanking::acrossAccounts()->create([
                'account_id' => $this->account->id,
                'project_id' => $this->project->id,
                'repo_keyword_id' => $keyword->id,
                'captured_on' => today()->subDays($row['days']),
                'position' => $row['position'],
                'searched_depth' => 100,
            ]);
        }

        $rankings = collect($this->page()->instance()->getRankings())->keyBy('keyword');

        $this->assertSame(4, $rankings['metadata viewer']['position']);
        $this->assertNull($rankings['metadata viewer']['movement']);
    }

    /** A project with no public listing gets an explanation, not an error. */
    public function test_an_unlinked_project_says_so(): void
    {
        $this->project->update(['wporg_slug' => null]);

        $this->page()
            ->assertOk()
            ->assertSee('Not linked to wordpress.org');
    }

    /*
     * The four headline cards.
     */

    protected function download(int $daysAgo, int $downloads): void
    {
        RepoDownload::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today()->subDays($daysAgo),
            'downloads' => $downloads,
        ]);
    }

    /**
     * All time comes from wordpress.org's summary endpoint, not from
     * summing our daily table. The daily endpoint stops at 730 days, so the
     * sum undercounts every plugin older than two years -- silently, and by
     * more the longer the plugin has been out.
     */
    public function test_the_download_total_is_wordpress_orgs_all_time_figure(): void
    {
        $this->fakeDownloadSummary(['all_time' => 78_203]);
        $this->snapshot();

        // Present, and pointedly not what is shown.
        $this->download(daysAgo: 1, downloads: 60);

        $this->assertSame(78_203, $this->page()->instance()->getHeadline()['downloads']);
    }

    public function test_conversion_is_installs_over_all_time_downloads(): void
    {
        $this->fakeDownloadSummary(['all_time' => 57_988]);
        $this->snapshot(['active_installs' => 10_000]);

        $headline = $this->page()->instance()->getHeadline();

        $this->assertSame(17.24, $headline['conversion']);
        $this->assertSame('very good', $headline['conversionLabel']);
    }

    /**
     * Null, never 0%. A missing half means there is no ratio at all, which
     * is a different statement from a ratio of nothing -- and 0% next to
     * 10,000 installs would read as a catastrophe rather than an absence.
     */
    public function test_conversion_needs_both_halves(): void
    {
        $this->fakeDownloadSummary(['all_time' => null]);
        $this->snapshot(['active_installs' => 10_000]);

        $headline = $this->page()->instance()->getHeadline();

        $this->assertNull($headline['conversion']);
        $this->assertNull($headline['conversionLabel']);
    }

    public function test_the_conversion_band_moves_with_the_rate(): void
    {
        $this->fakeDownloadSummary(['all_time' => 10_000]);
        $this->snapshot(['active_installs' => 100]);

        $headline = $this->page()->instance()->getHeadline();

        $this->assertSame(1.0, $headline['conversion']);
        $this->assertSame('low', $headline['conversionLabel']);
        $this->assertSame('danger', $headline['conversionColour']);
    }

    /**
     * active_installs is published in rounded buckets, so a plugin sitting
     * just over a bucket edge can report more installs than it has ever had
     * downloads. 224% would look like a bug and discredit every other
     * figure on the page.
     */
    public function test_conversion_is_capped_at_one_hundred_percent(): void
    {
        $this->fakeDownloadSummary(['all_time' => 4_468]);
        $this->snapshot(['active_installs' => 10_000]);

        $this->assertSame(100.0, $this->page()->instance()->getHeadline()['conversion']);
    }

    public function test_the_four_public_figures_are_on_the_page(): void
    {
        $this->fakeDownloadSummary(['all_time' => 78_203]);
        $this->snapshot(['active_installs' => 10_000, 'rating' => 98, 'num_ratings' => 9]);

        $this->page()
            ->assertOk()
            ->assertSee('Downloads')
            ->assertSee('Installations')
            ->assertSee('Rating')
            ->assertSee('Conversion')
            ->assertSee('78,203')
            ->assertSee('10,000')
            ->assertSee('98%');
    }

    /**
     * The rating is a percentage of nothing until somebody rates it, and
     * "0%" is how that would render. That reads as a terrible plugin rather
     * than a new one.
     */
    public function test_no_ratings_shows_no_score(): void
    {
        $this->snapshot(['rating' => 0, 'num_ratings' => 0]);

        $this->page()
            ->assertOk()
            ->assertSee('no ratings yet');
    }

    /**
     * The page chrome, asserted because half of it is ours.
     *
     * The breadcrumbs sit on the right through an override of Filament's
     * own header blade, in resources/views/vendor/filament-panels. An
     * override is invisible until a Filament upgrade ships a header this
     * one no longer resembles, at which point the layout quietly reverts or
     * breaks. This is the test that notices.
     */
    public function test_the_tabs_run_across_the_top_and_the_breadcrumbs_sit_right(): void
    {
        $this->snapshot();

        $html = $this->get(ProjectRepository::getUrl(['record' => $this->project]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('fi-page-sub-navigation-tabs', $html);
        $this->assertStringNotContainsString('fi-page-sub-navigation-sidebar', $html);

        // Our right-hand column, and the heading now printed ahead of the
        // breadcrumbs rather than beneath them.
        $this->assertStringContainsString('fi-header-end-ctn', $html);
        $this->assertLessThan(
            strpos($html, 'fi-breadcrumbs'),
            strpos($html, 'fi-header-heading'),
        );
    }

    /**
     * The refresh button queues, and never fetches inline.
     *
     * Asserting the dispatch rather than the numbers is the point: a
     * capture is four calls to wordpress.org, and the moment one of them
     * happens during this Livewire call the button has become the thing it
     * exists to avoid. TestCase's stray-request guard would catch the
     * fetch, but only the queue assertion says why it must not happen.
     */
    public function test_refreshing_queues_the_capture_rather_than_fetching_inline(): void
    {
        Queue::fake();

        $this->snapshot();

        $this->page()->callAction('refresh')->assertHasNoActionErrors();

        Queue::assertPushed(
            RefreshRepoStats::class,
            fn (RefreshRepoStats $job) => $job->projectId === $this->project->id,
        );
    }

    /**
     * A second press inside the cooldown buys nothing and costs a fetch.
     *
     * The guard is shared state rather than page state on purpose -- two
     * people on one project hold separate Livewire components -- so this
     * asserts through a fresh page, which is the case a disabled button
     * would miss.
     */
    public function test_a_second_refresh_inside_the_cooldown_is_refused(): void
    {
        Queue::fake();

        $this->snapshot();

        $this->page()->callAction('refresh');
        $this->page()->callAction('refresh');

        Queue::assertPushed(RefreshRepoStats::class, 1);
    }

    /**
     * Nothing to refresh without a slug, so nothing to press.
     */
    public function test_the_button_is_hidden_for_a_project_with_no_slug(): void
    {
        $this->project->update(['wporg_slug' => null]);

        $this->page()->assertActionHidden('refresh');
    }
}
