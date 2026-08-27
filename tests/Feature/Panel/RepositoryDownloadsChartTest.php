<?php

namespace Tests\Feature\Panel;

use App\Filament\Widgets\RepositoryDownloadsChart;
use App\Models\Account;
use App\Models\Project;
use App\Models\RepoDownload;
use App\Models\RepoRelease;
use App\Models\User;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The period control.
 *
 * A date filter is the easiest thing in a dashboard to get quietly wrong:
 * an off-by-one drops the day everybody came to look at, a half-typed range
 * renders an empty chart that reads as "no downloads", and a reversed range
 * looks identical to a plugin nobody installed. None of those announce
 * themselves, so every branch of the resolution is pinned here -- and the
 * chart is asserted on its actual labels, not just on the dates.
 */
class RepositoryDownloadsChartTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, because "last 90 days" is only assertable against a fixed
        // today, and a test that spans midnight fails once a year.
        Carbon::setTestNow('2026-08-28 14:00:00');

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function widget(array $filters = []): Testable
    {
        $component = Livewire::test(RepositoryDownloadsChart::class, [
            'record' => $this->project,
        ]);

        foreach ($filters as $key => $value) {
            $component->set("filters.{$key}", $value);
        }

        return $component;
    }

    /** @param  array<string, string>  $filters */
    protected function window(array $filters = []): array
    {
        $widget = $this->widget($filters)->instance();

        $resolved = $widget->resolveWindow();

        return [
            $resolved['from']->toDateString(),
            $resolved['until']->toDateString(),
        ];
    }

    /**
     * The chart payload. getCachedData() is protected in Filament, and
     * asserting on the datasets is the only way to know the window actually
     * reached the query rather than merely resolving correctly.
     *
     * @return array<string, mixed>
     */
    protected function data(Testable $component): array
    {
        return (fn () => $this->getCachedData())->call($component->instance());
    }

    /** Downloads for every day in a span, so labels are predictable. */
    protected function seedDownloads(string $from, string $until, int $each = 40): void
    {
        $date = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($until);

        while ($date <= $end) {
            RepoDownload::create([
                'account_id' => $this->account->id,
                'project_id' => $this->project->id,
                'date' => $date->toDateString(),
                'downloads' => $each,
            ]);

            $date = $date->addDay();
        }
    }

    public function test_it_opens_on_six_months(): void
    {
        $this->assertSame(['2026-03-01', '2026-08-28'], $this->window());
    }

    public function test_today_is_a_single_day_not_a_span(): void
    {
        $this->assertSame(['2026-08-28', '2026-08-28'], $this->window(['period' => 'today']));
    }

    /**
     * The one most likely to be off by one. Yesterday must not include
     * today, or the number shown is a day still being counted.
     */
    public function test_yesterday_excludes_today(): void
    {
        $this->assertSame(['2026-08-27', '2026-08-27'], $this->window(['period' => 'yesterday']));
    }

    public function test_a_relative_period_counts_back_from_today(): void
    {
        $this->assertSame(['2026-05-30', '2026-08-28'], $this->window(['period' => '90']));
        $this->assertSame(['2026-08-21', '2026-08-28'], $this->window(['period' => '7']));
    }

    public function test_a_chosen_day_becomes_a_window_of_one(): void
    {
        $this->assertSame(
            ['2026-04-14', '2026-04-14'],
            $this->window(['period' => 'day', 'date' => '2026-04-14']),
        );
    }

    public function test_a_chosen_range_is_used_verbatim(): void
    {
        $this->assertSame(
            ['2026-02-01', '2026-02-28'],
            $this->window(['period' => 'range', 'from' => '2026-02-01', 'until' => '2026-02-28']),
        );
    }

    /**
     * Selecting "a date range" and picking the first date is a form
     * mid-edit. Treating the blank end as "today" or as "the beginning of
     * time" would redraw the chart on every keystroke with a window nobody
     * asked for.
     */
    public function test_a_half_filled_range_falls_back_rather_than_guessing(): void
    {
        $this->assertSame(
            ['2026-03-01', '2026-08-28'],
            $this->window(['period' => 'range', 'from' => '2026-02-01']),
        );
    }

    /**
     * A reversed range shows the empty state, which is honest, rather than
     * silently swapping the dates and showing a window that was never
     * requested but looks entirely correct.
     */
    public function test_a_reversed_range_stays_reversed_and_renders_empty(): void
    {
        $this->seedDownloads('2026-02-01', '2026-03-01');

        $widget = $this->widget([
            'period' => 'range',
            'from' => '2026-03-01',
            'until' => '2026-02-01',
        ])->instance();

        $this->assertSame(['2026-03-01', '2026-02-01'], [
            $widget->resolveWindow()['from']->toDateString(),
            $widget->resolveWindow()['until']->toDateString(),
        ]);
        $this->assertTrue($widget->isEmpty());
    }

    /** Rubbish in the state must not take the page down with it. */
    public function test_an_unparseable_date_falls_back_instead_of_throwing(): void
    {
        $this->assertSame(
            ['2026-08-28', '2026-08-28'],
            $this->window(['period' => 'day', 'date' => 'not-a-date']),
        );
    }

    public function test_the_window_bounds_the_data_at_both_ends(): void
    {
        $this->seedDownloads('2026-07-01', '2026-07-31');

        $data = $this->data($this->widget([
            'period' => 'range',
            'from' => '2026-07-10',
            'until' => '2026-07-12',
        ]));

        $this->assertSame(['2026-07-10', '2026-07-11', '2026-07-12'], $data['labels']);
    }

    /**
     * A window with nothing in it is not the same as a project with nothing
     * in it, and the empty state says which.
     */
    public function test_a_window_before_any_data_is_empty_rather_than_zero(): void
    {
        $this->seedDownloads('2026-07-01', '2026-07-31');

        $widget = $this->widget(['period' => 'day', 'date' => '2026-01-01'])->instance();

        $this->assertTrue($widget->isEmpty());
        $this->assertSame('No downloads recorded in this window', $widget->getEmptyStateHeading());
    }

    /**
     * Under the regression floor, trend() returns a row of nulls and the
     * baseline is just the points again. Drawing them would put two
     * confident-looking entries in the legend describing nothing.
     */
    public function test_a_short_window_draws_no_trend_or_baseline(): void
    {
        $this->seedDownloads('2026-08-25', '2026-08-28');

        $data = $this->data($this->widget(['period' => 'range', 'from' => '2026-08-25', 'until' => '2026-08-28']));

        $labels = array_column($data['datasets'], 'label');

        $this->assertContains('Daily downloads', $labels);
        $this->assertNotContains('Baseline', $labels);
        $this->assertSame([], array_filter($labels, fn ($l) => str_starts_with($l, 'Trend')));
    }

    public function test_a_long_enough_window_draws_both(): void
    {
        $this->seedDownloads('2026-07-01', '2026-07-31');

        $data = $this->data($this->widget(['period' => 'range', 'from' => '2026-07-01', 'until' => '2026-07-31']));

        $labels = array_column($data['datasets'], 'label');

        $this->assertContains('Baseline', $labels);
        $this->assertNotEmpty(array_filter($labels, fn ($l) => str_starts_with($l, 'Trend')));
    }

    /**
     * A single point on a line chart with invisible markers is a blank
     * rectangle. "Today" asks for exactly that, so short windows show
     * their points.
     */
    public function test_a_short_window_shows_its_points(): void
    {
        $this->seedDownloads('2026-08-26', '2026-08-28');

        $data = $this->data($this->widget(['period' => 'today']));

        $downloads = collect($data['datasets'])->firstWhere('label', 'Daily downloads');

        $this->assertSame(1, count($downloads['data']));
        $this->assertGreaterThan(0, $downloads['pointRadius']);
    }

    /** Release markers still land when the window is a single day. */
    public function test_a_release_on_the_chosen_day_is_marked(): void
    {
        $this->seedDownloads('2026-06-01', '2026-06-30');

        RepoRelease::create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'version' => '3.1.0',
            'released_on' => '2026-06-15',
            'source' => RepoRelease::FROM_SVN,
        ]);

        $data = $this->data($this->widget(['period' => 'day', 'date' => '2026-06-15']));

        $releases = collect($data['datasets'])->firstWhere('label', 'Release');

        $this->assertSame(['3.1.0'], $releases['releaseVersions']);
    }

    /** The control collapses to an icon, so the window is stated in words. */
    public function test_the_description_names_the_window(): void
    {
        $widget = $this->widget(['period' => 'range', 'from' => '2026-02-01', 'until' => '2026-02-28'])
            ->instance();

        $this->assertStringContainsString('1 Feb 2026 to 28 Feb 2026', $widget->getDescription());

        $single = $this->widget(['period' => 'yesterday'])->instance();

        // One date, not "27 Aug to 27 Aug".
        $this->assertStringContainsString('27 Aug 2026.', $single->getDescription());
    }
}
