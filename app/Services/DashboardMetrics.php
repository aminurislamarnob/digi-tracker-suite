<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Everything the dashboard reads.
 *
 * One rule, and it is load-bearing: this class touches `daily_stats` and
 * nothing else. We chose MySQL, where aggregating JSON across a quarter of
 * a million site_reports rows on every page load is slow enough to make
 * the product feel broken. If a chart needs a number that is not rolled up
 * yet, the fix is a column in the rollup -- never a query here.
 */
class DashboardMetrics
{
    public function __construct(protected int $days = 30) {}

    /**
     * The series backing every sparkline, oldest first.
     *
     * Missing days are simply absent rather than zero-filled: a day the
     * rollup never ran is not a day with no installs, and drawing it as
     * zero would invent a cliff that never happened.
     */
    public function series(Project $project): Collection
    {
        return DailyStat::query()
            ->where('project_id', $project->id)
            ->where('date', '>=', now()->subDays($this->days * 2)->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function latest(Project $project): ?DailyStat
    {
        return DailyStat::query()
            ->where('project_id', $project->id)
            ->orderByDesc('date')
            ->first();
    }

    /**
     * The three numbers at the top of the page, each with the change on the
     * previous period of equal length.
     *
     * Labelled "tracked installs", never "active installs". The figure will
     * read far below the wordpress.org number, and that gap is the opt-in
     * rate -- calling it "active installs" would make it look like a bug.
     */
    public function headline(Project $project): array
    {
        $series = $this->series($project);

        $current = $series->where('date', '>=', now()->subDays($this->days)->startOfDay());
        $previous = $series->diff($current);

        $latest = $series->last();

        return [
            'tracked_installs' => [
                'label' => 'Tracked installs',
                'value' => $latest?->active_installs ?? 0,
                'delta' => $this->delta(
                    $latest?->active_installs ?? 0,
                    $previous->last()?->active_installs ?? 0,
                ),
                'spark' => $series->pluck('active_installs')->all(),
            ],
            'opt_in_rate' => [
                'label' => 'Opt-in rate',
                'value' => $this->ratio($current->sum('opted_in'), $current->sum('opted_in') + $current->sum('skipped')),
                'delta' => $this->delta(
                    $this->ratio($current->sum('opted_in'), $current->sum('opted_in') + $current->sum('skipped')),
                    $this->ratio($previous->sum('opted_in'), $previous->sum('opted_in') + $previous->sum('skipped')),
                ),
                'suffix' => '%',
                'spark' => $series->map(fn ($row) => $this->ratio($row->opted_in, $row->opted_in + $row->skipped))->all(),
            ],
            'churn_rate' => [
                'label' => 'Churn rate',
                'value' => $this->ratio($current->sum('deactivations'), $current->first()?->active_installs ?? 0),
                'delta' => $this->delta(
                    $this->ratio($current->sum('deactivations'), $current->first()?->active_installs ?? 0),
                    $this->ratio($previous->sum('deactivations'), $previous->first()?->active_installs ?? 0),
                ),
                'suffix' => '%',
                'inverted' => true,
                'spark' => $series->pluck('deactivations')->all(),
            ],
        ];
    }

    /**
     * A distribution ready to draw, biggest slice first, with a long tail
     * folded into "Other" so a donut of ninety locales stays readable.
     */
    public function distribution(Project $project, string $key, int $limit = 6): array
    {
        $counts = $this->latest($project)?->{$key} ?? [];

        if ($counts === []) {
            return [];
        }

        arsort($counts);

        $top = array_slice($counts, 0, $limit, true);
        $rest = array_sum(array_slice($counts, $limit, null, true));

        if ($rest > 0) {
            $top['Other'] = $rest;
        }

        return $top;
    }

    protected function ratio(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    /**
     * Percentage change, or null when there is no prior period to compare
     * against. Null renders as nothing at all -- a brand-new project
     * showing "+100%" would be reporting growth it has not measured.
     */
    protected function delta(int|float $now, int|float $before): ?float
    {
        if ($before <= 0) {
            return null;
        }

        return round(($now - $before) / $before * 100, 1);
    }
}
