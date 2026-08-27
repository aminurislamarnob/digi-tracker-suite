<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Projects\Pages\ProjectRepository;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Project;
use App\Models\RepoKeyword;
use App\Models\RepoRanking;
use App\Models\RepoRelease;
use App\Models\RepoSnapshot;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
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
    use RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

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
}
