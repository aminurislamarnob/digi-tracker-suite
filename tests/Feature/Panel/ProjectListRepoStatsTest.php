<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ProjectRepository;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Project;
use App\Models\RepoDownload;
use App\Models\RepoSnapshot;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\FakesWordPressOrg;
use Tests\TestCase;

/**
 * The public record, on the screen you land on.
 *
 * The list page used to show only what opted-in sites told us, which for
 * every project here is currently zero and will stay zero until a release
 * carrying the SDK is out. The repository has been publishing all along,
 * so it leads now -- in the columns, in the tab order, and in where a
 * click on a project actually goes.
 *
 * What is asserted below is mostly the refusals. A project with no public
 * listing has no install figure, no rating and no opt-in rate, and each of
 * those has a plausible wrong answer -- 0, 0.0 / 5, 0% -- that would read
 * as a measurement rather than an absence.
 */
class ProjectListRepoStatsTest extends TestCase
{
    use FakesWordPressOrg, RefreshDatabase;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Linking a project queues its first capture -- see
         * Project::refreshRepositoryOnLink. Faked before the project is
         * created, or the sync queue runs a real fetch in setUp.
         */
        Queue::fake();

        $this->fakeDownloadSummary();

        $this->account = Account::factory()->create();

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

    protected function snapshot(Project $project, array $attributes = []): RepoSnapshot
    {
        return RepoSnapshot::create(array_merge([
            'account_id' => $this->account->id,
            'project_id' => $project->id,
            'captured_on' => now()->toDateString(),
            'active_installs' => 500,
            'rating' => 96,
            'num_ratings' => 12,
            'support_threads' => 3,
            'resolved_threads' => 2,
            'version' => '2.2.4',
        ], $attributes));
    }

    protected function download(Project $project, int $daysAgo, int $downloads): void
    {
        RepoDownload::create([
            'account_id' => $this->account->id,
            'project_id' => $project->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'downloads' => $downloads,
        ]);
    }

    public function test_the_public_figures_are_shown_on_the_list(): void
    {
        $project = $this->project(['wporg_slug' => 'metadata-viewer']);
        $this->snapshot($project);

        Livewire::test(ListProjects::class)
            ->assertOk()
            ->assertSee('Public installs')
            ->assertSee('500')
            ->assertSee('4.8 / 5')
            ->assertSee('12 ratings');
    }

    /**
     * The column is a 30-day figure, so it has to be one. Summing the whole
     * history would render an enormous number under a heading that says
     * otherwise, and nobody would question it.
     */
    public function test_the_downloads_column_counts_only_the_last_thirty_days(): void
    {
        $project = $this->project(['wporg_slug' => 'metadata-viewer']);

        $this->download($project, daysAgo: 1, downloads: 10);
        $this->download($project, daysAgo: 29, downloads: 15);
        $this->download($project, daysAgo: 200, downloads: 9_000);

        $row = Livewire::test(ListProjects::class)->instance()
            ->getTable()->getRecords()->first();

        $this->assertSame(25, (int) $row->downloads_30d);
    }

    /**
     * A rating exists in the data whether or not anyone has left one, and
     * "0.0 / 5" is how a plugin with no ratings would render it. That reads
     * as a terrible plugin rather than a new one.
     */
    public function test_no_ratings_yet_shows_nothing_rather_than_a_zero_score(): void
    {
        $project = $this->project(['wporg_slug' => 'fresh-plugin']);
        $this->snapshot($project, ['rating' => 0, 'num_ratings' => 0]);

        Livewire::test(ListProjects::class)
            ->assertOk()
            ->assertDontSee('0 / 5')
            ->assertDontSee('0.0 / 5');
    }

    /**
     * The number this whole feature exists to produce. Null when there is
     * no public figure to divide by -- 0% would claim nobody opted in.
     */
    public function test_the_opt_in_rate_needs_both_halves(): void
    {
        $with = $this->project(['name' => 'Linked', 'wporg_slug' => 'linked']);
        $this->snapshot($with, ['active_installs' => 500]);

        DailyStat::create([
            'account_id' => $this->account->id,
            'project_id' => $with->id,
            'date' => now()->toDateString(),
            'active_installs' => 125,
        ]);

        Livewire::test(ListProjects::class)
            ->assertOk()
            ->assertSee('25%');
    }

    public function test_an_unlinked_project_reports_no_public_figures(): void
    {
        $this->project(['name' => 'Unpublished', 'wporg_slug' => null]);

        $page = Livewire::test(ListProjects::class)->assertOk();

        // Not a zero anywhere: no listing is the absence of a measurement.
        $page->assertDontSee('0%');
        $page->assertDontSee('0 / 5');
    }

    /**
     * Where a click lands. The repository dashboard is the screen with
     * something on it, so linked projects go straight there.
     */
    public function test_a_linked_project_opens_on_its_repository_dashboard(): void
    {
        $project = $this->project(['slug' => 'metadata-viewer', 'wporg_slug' => 'metadata-viewer']);

        $url = Livewire::test(ListProjects::class)->instance()
            ->getTable()->getRecordUrl($project);

        $this->assertSame(ProjectRepository::getUrl(['record' => $project]), $url);
    }

    /**
     * And where it does not. Sending an unlinked project to a page whose
     * entire content is "not linked to wordpress.org" would bury its own
     * details behind a second click for nothing.
     */
    public function test_an_unlinked_project_opens_on_its_overview(): void
    {
        $project = $this->project(['slug' => 'unpublished', 'wporg_slug' => null]);

        $url = Livewire::test(ListProjects::class)->instance()
            ->getTable()->getRecordUrl($project);

        $this->assertSame(ViewProject::getUrl(['record' => $project]), $url);
    }

    /** Repository leads the tabs, because it is the tab with data in it. */
    public function test_repository_is_the_first_tab(): void
    {
        $project = $this->project(['slug' => 'metadata-viewer', 'wporg_slug' => 'metadata-viewer']);

        $page = Livewire::test(ProjectRepository::class, ['record' => $project->slug])->instance();

        $labels = collect(ProjectResource::getRecordSubNavigation($page))
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertSame(['Repository', 'Overview', 'Reports', 'Edit'], $labels);
    }

    /**
     * One eager load and one aggregate for the page, not a query per row.
     * The count is asserted rather than the shape, because an N+1 here is
     * invisible until a list gets long.
     */
    public function test_the_public_columns_do_not_add_a_query_per_row(): void
    {
        foreach (range(1, 6) as $i) {
            $project = $this->project(['slug' => "plugin-{$i}", 'wporg_slug' => "plugin-{$i}"]);
            $this->snapshot($project);
            $this->download($project, daysAgo: 1, downloads: 10);
        }

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        Livewire::test(ListProjects::class)->assertOk();

        // Generous, because the panel itself issues plenty. The point is
        // that six projects do not cost six extra snapshot lookups.
        $this->assertLessThan(60, $queries, "The list page ran {$queries} queries for six projects.");
    }
}
