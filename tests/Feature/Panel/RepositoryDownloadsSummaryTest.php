<?php

namespace Tests\Feature\Panel;

use App\Filament\Widgets\RepositoryDownloadsSummary;
use App\Models\Account;
use App\Models\Project;
use App\Models\RepoSnapshot;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\FakesWordPressOrg;
use Tests\TestCase;

/**
 * Today, yesterday, the week, and all time.
 *
 * These four exist to agree with the plugin's own page on wordpress.org,
 * and the whole design follows from that: they are read from the summary
 * endpoint the plugin directory itself calls, not derived from our daily
 * table. Deriving them looked correct and was not -- the daily series stops
 * at 730 days, so all time undercounted every plugin older than two years,
 * and it is refreshed nightly, so "today" was whatever the figure had been
 * at 03:00.
 *
 * What is worth testing, then, is not arithmetic. It is that the live
 * figures are used, that a wordpress.org outage degrades to the last
 * capture and says so, and that a failure is never cached.
 */
class RepositoryDownloadsSummaryTest extends TestCase
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

    protected function snapshot(array $attributes = []): RepoSnapshot
    {
        return RepoSnapshot::acrossAccounts()->create(array_merge([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'captured_on' => today(),
            'active_installs' => 500,
        ], $attributes));
    }

    protected function figures(): array
    {
        return Livewire::test(RepositoryDownloadsSummary::class, ['record' => $this->project])
            ->instance()
            ->getFigures();
    }

    /**
     * The numbers on the card are the numbers on the plugin's page, because
     * they are the same request. This is the whole point of the widget.
     */
    public function test_the_figures_are_wordpress_orgs_own(): void
    {
        $this->fakeDownloadSummary();

        $figures = $this->figures();

        $this->assertSame(10, $figures['today']);
        $this->assertSame(9, $figures['yesterday']);
        $this->assertSame(63, $figures['lastWeek']);
        $this->assertSame(4468, $figures['allTime']);
        $this->assertTrue($figures['live']);
    }

    /** Every figure arrives as a string and has to survive the trip. */
    public function test_string_figures_are_cast(): void
    {
        $this->fakeDownloadSummary(['today' => 0]);

        // Zero downloads today is a real answer, and must not become null
        // on its way through a loose check.
        $this->assertSame(0, $this->figures()['today']);
    }

    /**
     * A public API we are a guest on, read on every page load. Once per
     * quarter hour, not once per refresh.
     */
    public function test_the_summary_is_fetched_once_and_then_cached(): void
    {
        $this->fakeDownloadSummary();

        $this->figures();
        $this->figures();
        $this->figures();

        Http::assertSentCount(1);
    }

    /**
     * The failure mode this widget has to get right. A dashboard showing
     * yesterday's numbers as though they were live is worse than one that
     * says it could not reach wordpress.org.
     */
    public function test_an_outage_falls_back_to_the_last_capture_and_admits_it(): void
    {
        $this->snapshot([
            'downloads_today' => 7,
            'downloads_yesterday' => 12,
            'downloads_last_week' => 55,
            'downloaded' => 4400,
        ]);

        $this->fakeWordPressOrgDown();

        $figures = $this->figures();

        $this->assertSame(7, $figures['today']);
        $this->assertSame(4400, $figures['allTime']);
        $this->assertFalse($figures['live']);
    }

    /**
     * A cached failure would turn a momentary blip into fifteen minutes of
     * stale numbers -- an outage we inflicted on ourselves.
     */
    public function test_a_failure_is_never_cached(): void
    {
        /*
         * A stateful stub rather than a sequence, and rather than two
         * Http::fake() calls. A second Http::fake() appends behind the
         * first, so the failing pattern would keep winning; and a sequence
         * would be consumed an unpredictable number of times, because
         * WordPressOrg::request() retries before giving up.
         */
        $reachable = false;

        Http::fake([
            'api.wordpress.org/stats/plugin/1.0/downloads.php*historical_summary*' => function () use (&$reachable) {
                return $reachable
                    ? Http::response(['today' => '10', 'yesterday' => '9', 'last_week' => '63', 'all_time' => '4468'])
                    : Http::response('', 503);
            },
        ]);

        $this->assertNull($this->figures()['today']);

        $reachable = true;

        // The very next look succeeds, rather than waiting out a cache
        // entry written from a blip.
        $this->assertSame(10, $this->figures()['today']);
    }

    /** No snapshot and no wordpress.org is four dashes, not four zeros. */
    public function test_nothing_at_all_reports_nothing_rather_than_zero(): void
    {
        $this->fakeWordPressOrgDown();

        $figures = $this->figures();

        $this->assertNull($figures['today']);
        $this->assertNull($figures['allTime']);
    }

    public function test_the_cards_render(): void
    {
        $this->fakeDownloadSummary();

        Livewire::test(RepositoryDownloadsSummary::class, ['record' => $this->project])
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('Yesterday')
            ->assertSee('Last 7 days')
            ->assertSee('All time')
            ->assertSee('4,468')
            ->assertSee('still being counted');
    }

    /** Nothing public to summarise, so nothing is drawn and nothing fetched. */
    public function test_an_unlinked_project_renders_no_cards(): void
    {
        $this->project->update(['wporg_slug' => null]);

        Livewire::test(RepositoryDownloadsSummary::class, ['record' => $this->project])
            ->assertOk()
            ->assertDontSee('Last 7 days');

        Http::assertNothingSent();
    }
}
