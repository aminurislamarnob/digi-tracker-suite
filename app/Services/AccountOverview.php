<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Project;
use App\Models\RepoSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The whole account on one screen.
 *
 * DashboardMetrics answers questions about one project; this answers them
 * about all of them at once, which is a different question and not a loop
 * over the first. Summing per-project figures in PHP would issue a query
 * per project and then get the arithmetic wrong in the one place it
 * matters -- installs, where the right total is each project's own latest
 * day rather than everyone's figure on some shared date.
 *
 * The same rule as DashboardMetrics applies and for the same reason: this
 * reads daily_stats and the repo_* tables, never site_reports. It also
 * inherits that class's refusals. A figure with no prior period to compare
 * against reports no change rather than a confident +100%, and a project
 * that has never been captured contributes nothing rather than a zero.
 */
class AccountOverview
{
    public function __construct(protected int $days = 30) {}

    /**
     * Each project's most recent rollup, keyed by project id.
     *
     * Installs are a level, not a total: the current figure is whatever the
     * newest row says, and rows do not all land on the same date -- a
     * project whose plugin has not phoned home today has no row for today.
     * Summing everyone's figure on a single shared date would therefore
     * drop any project that happened to be quiet, which looks like installs
     * falling off a cliff overnight.
     *
     * @return Collection<int, DailyStat>
     */
    public function latestPerProject(): Collection
    {
        $latest = DailyStat::query()
            ->selectRaw('project_id, max(date) as date')
            ->groupBy('project_id');

        return DailyStat::query()
            ->joinSub($latest, 'newest', fn ($join) => $join
                ->on('daily_stats.project_id', '=', 'newest.project_id')
                ->on('daily_stats.date', '=', 'newest.date'))
            ->select('daily_stats.*')
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Daily totals across every project, oldest first.
     *
     * Summed per date rather than zero-filled, on the same argument
     * DashboardMetrics makes: a day the rollup never ran is not a day with
     * no installs. Because the rollup writes every project at once, such a
     * day is missing for all of them and simply is not on the axis -- which
     * is a gap in the line, not a crash in it.
     *
     * @return Collection<int, object{date: Carbon, active_installs: int, deactivations: int, new_installs: int}>
     */
    public function series(): Collection
    {
        return DailyStat::query()
            ->selectRaw('date, sum(active_installs) as active_installs, sum(deactivations) as deactivations, sum(new_installs) as new_installs')
            ->where('date', '>=', now()->subDays($this->days * 2)->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => (object) [
                'date' => Carbon::parse($row->date),
                'active_installs' => (int) $row->active_installs,
                'deactivations' => (int) $row->deactivations,
                'new_installs' => (int) $row->new_installs,
            ]);
    }

    /**
     * The four figures at the top of the dashboard.
     *
     * @return array<string, array<string, mixed>>
     */
    public function headline(): array
    {
        $series = $this->series();
        $cutoff = now()->subDays($this->days)->startOfDay();

        $current = $series->filter(fn ($row) => $row->date->gte($cutoff));
        $previous = $series->reject(fn ($row) => $row->date->gte($cutoff));

        $installs = (int) $this->latestPerProject()->sum('active_installs');

        return [
            'projects' => [
                'label' => 'Active projects',
                'value' => Project::query()->where('is_active', true)->count(),
                /*
                 * No delta and no sparkline. Projects are created by hand,
                 * a handful at a time -- a trend line over four points is
                 * decoration pretending to be a measurement.
                 */
                'delta' => null,
                'quiet' => true,
                'description' => $this->projectsCaption(),
            ],
            'installs' => [
                'label' => 'Tracked installs',
                'value' => $installs,
                'delta' => $this->delta($installs, (int) $previous->last()?->active_installs),
                'spark' => $series->pluck('active_installs')->all(),
                /*
                 * "Tracked", never "active". This counts sites that opted
                 * in, which reads far below the figure wordpress.org
                 * publishes; the gap is the opt-in rate, and a label
                 * claiming otherwise makes it look like a bug.
                 */
                'description' => 'sites that opted in',
            ],
            'downloads' => [
                'label' => 'Public downloads',
                'value' => $this->publicDownloads(),
                'delta' => null,
                'quiet' => true,
                'description' => $this->downloadsCaption(),
            ],
            'churn' => [
                'label' => 'Churn rate',
                'value' => $this->ratio($current->sum('deactivations'), (int) $current->first()?->active_installs),
                'delta' => $this->delta(
                    $this->ratio($current->sum('deactivations'), (int) $current->first()?->active_installs),
                    $this->ratio($previous->sum('deactivations'), (int) $previous->first()?->active_installs),
                ),
                'suffix' => '%',
                'inverted' => true,
                'spark' => $series->pluck('deactivations')->all(),
                'description' => "deactivations over {$this->days} days",
            ],
        ];
    }

    /**
     * Lifetime downloads across every linked project.
     *
     * Null rather than zero when nothing has been captured yet. Zero would
     * be a claim that these plugins have never been downloaded, which is a
     * statement about wordpress.org; the truth is a statement about us,
     * and it is that we have not looked.
     */
    public function publicDownloads(): ?int
    {
        $snapshots = $this->latestSnapshots();

        if ($snapshots->isEmpty()) {
            return null;
        }

        return (int) $snapshots->sum('downloaded');
    }

    /**
     * The newest capture per project, keyed by project id.
     *
     * @return Collection<int, RepoSnapshot>
     */
    public function latestSnapshots(): Collection
    {
        $latest = RepoSnapshot::query()
            ->selectRaw('project_id, max(captured_on) as captured_on')
            ->groupBy('project_id');

        return RepoSnapshot::query()
            ->joinSub($latest, 'newest', fn ($join) => $join
                ->on('repo_snapshots.project_id', '=', 'newest.project_id')
                ->on('repo_snapshots.captured_on', '=', 'newest.captured_on'))
            ->select('repo_snapshots.*')
            ->get()
            ->keyBy('project_id');
    }

    /**
     * One row per project, for the table under the chart.
     *
     * Every figure is nullable and every null means "not measured", never
     * "measured as nothing" -- the table renders a dash for each, because a
     * zero in a column of real numbers gets read as a real number.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function projects(): Collection
    {
        $stats = $this->latestPerProject();
        $snapshots = $this->latestSnapshots();

        return Project::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) use ($stats, $snapshots) {
                $stat = $stats->get($project->id);
                $snapshot = $snapshots->get($project->id);

                return [
                    'project' => $project,
                    'tracked' => $stat?->active_installs,
                    'publicInstalls' => $snapshot?->active_installs,
                    'downloads' => $snapshot?->downloaded,
                    'version' => $snapshot?->version ?? $this->topVersion($stat),
                    'rating' => $snapshot?->rating,
                    'lastRollup' => $stat?->date,
                    'lastCapture' => $snapshot?->captured_on,
                    'optInRate' => app(RepoAnalytics::class)
                        ->optInRate($stat?->active_installs, $snapshot?->active_installs),
                ];
            });
    }

    /**
     * Projects with something worth looking at, worst first.
     *
     * Deliberately specific. "Something is wrong" is not actionable, so
     * each entry names the thing and links to the page that shows it --
     * and the list is empty, rather than reassuring, when there is nothing
     * to say.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function attention(): Collection
    {
        $stats = $this->latestPerProject();
        $snapshots = $this->latestSnapshots();
        $yesterday = now()->subDay()->startOfDay();

        return Project::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) use ($stats, $snapshots, $yesterday) {
                $stat = $stats->get($project->id);
                $snapshot = $snapshots->get($project->id);

                /*
                 * Exactly the state that made a chart look broken: a
                 * project linked to wordpress.org that nothing has captured
                 * yet. Named first because it is the one with a one-click
                 * fix -- the Refresh button on its repository page.
                 */
                if ($project->isOnRepository() && $snapshot === null) {
                    return $this->flag($project, 'danger', 'Never captured',
                        'Linked to wordpress.org, but no public record has been fetched. Refresh it, or wait for the 03:00 capture.');
                }

                if ($stat === null) {
                    return $this->flag($project, 'warning', 'No telemetry yet',
                        'Nothing has reported in. Expected until a release carrying the SDK reaches real sites.');
                }

                if ($stat->date->lt($yesterday)) {
                    return $this->flag($project, 'warning', 'Rollup is stale',
                        'Last rolled up '.$stat->date->diffForHumans().'. The nightly build may not be running.');
                }

                if ($snapshot && $snapshot->captured_on->lt($yesterday)) {
                    return $this->flag($project, 'warning', 'Capture is stale',
                        'Public record last fetched '.$snapshot->captured_on->diffForHumans().'.');
                }

                return null;
            })
            ->filter()
            ->sortBy(fn (array $row) => $row['colour'] === 'danger' ? 0 : 1)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function flag(Project $project, string $colour, string $title, string $body): array
    {
        return [
            'project' => $project,
            'colour' => $colour,
            'title' => $title,
            'body' => $body,
        ];
    }

    /** The version most tracked sites are running, where we know it. */
    protected function topVersion(?DailyStat $stat): ?string
    {
        $versions = $stat?->by_version ?? [];

        if (! is_array($versions) || $versions === []) {
            return null;
        }

        arsort($versions);

        return (string) array_key_first($versions);
    }

    protected function projectsCaption(): string
    {
        $linked = Project::query()->where('is_active', true)->whereNotNull('wporg_slug')->count();

        return $linked === 0
            ? 'none linked to wordpress.org'
            : "{$linked} linked to wordpress.org";
    }

    protected function downloadsCaption(): string
    {
        return $this->publicDownloads() === null
            ? 'nothing captured yet'
            : 'lifetime, as wordpress.org reports it';
    }

    protected function ratio(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    /**
     * Percentage change, or null when there is nothing to compare against.
     * Matches DashboardMetrics deliberately: two dashboards disagreeing
     * about what "no earlier period" looks like is worse than either
     * choice on its own.
     */
    protected function delta(int|float $now, int|float $before): ?float
    {
        if ($before <= 0) {
            return null;
        }

        return round(($now - $before) / $before * 100, 1);
    }
}
