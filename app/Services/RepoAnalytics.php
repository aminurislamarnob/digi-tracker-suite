<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Project;
use App\Models\RepoRelease;
use App\Models\RepoSnapshot;
use App\Support\CurrentAccount;

/**
 * The derived numbers: trend, baseline, momentum, opt-in rate, adoption
 * speed.
 *
 * Deliberately pure functions over arrays, and deliberately server-side.
 * The same arithmetic done in chart JavaScript cannot be tested, and every
 * one of these has a wrong answer that looks entirely plausible on a chart
 * -- a regression line through two points, a smoothing window that quietly
 * invents a value at the edges, an average that treats "unranked" as a
 * large number. Each guard below exists because the wrong version renders
 * beautifully.
 */
class RepoAnalytics
{
    /** Below this many points a regression line is decoration, not a finding. */
    public const MIN_TREND_POINTS = 7;

    /**
     * Least-squares fit over a series, with the direction it implies.
     *
     * @param  array<int, int|float|null>  $values
     * @return array{values: array<int, float|null>, slope: float, direction: string}
     */
    public function trend(array $values): array
    {
        $points = [];

        foreach (array_values($values) as $index => $value) {
            if ($value !== null) {
                $points[$index] = (float) $value;
            }
        }

        if (count($points) < self::MIN_TREND_POINTS) {
            return [
                'values' => array_fill(0, count($values), null),
                'slope' => 0.0,
                'direction' => 'flat',
            ];
        }

        $n = count($points);
        $sumX = array_sum(array_keys($points));
        $sumY = array_sum($points);
        $sumXY = 0.0;
        $sumXX = 0.0;

        foreach ($points as $x => $y) {
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denominator = ($n * $sumXX) - ($sumX * $sumX);

        // Every point on one x -- impossible here, but a division by zero
        // would render as a blank chart with no explanation.
        if (abs($denominator) < 1e-9) {
            return [
                'values' => array_fill(0, count($values), null),
                'slope' => 0.0,
                'direction' => 'flat',
            ];
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        $line = [];

        foreach (array_keys(array_values($values)) as $index) {
            // Downloads cannot be negative, so a falling line is clamped
            // rather than drawn through the axis.
            $line[] = round(max(0, ($slope * $index) + $intercept), 2);
        }

        return [
            'values' => $line,
            'slope' => round($slope, 4),
            'direction' => match (true) {
                $slope > 0.01 => 'rising',
                $slope < -0.01 => 'falling',
                default => 'flat',
            },
        ];
    }

    /**
     * A Gaussian-weighted moving average.
     *
     * Daily download counts are too noisy to read directly -- weekday
     * rhythm alone swamps the signal -- so spikes need a smooth expectation
     * to be measured against. Gaussian rather than a flat mean because a
     * boxcar average produces its own artefacts: a single large day appears
     * as a plateau exactly as wide as the window, which reads as a
     * sustained rise that never happened.
     *
     * One caveat matters more than it looks. Within `$window` of either end
     * the kernel is truncated, so those points average neighbours on one
     * side only. On a rising series that drags the final values down; on a
     * falling one it props them up. The bias is worst at the most recent
     * day -- exactly where everyone looks first -- so callers should treat
     * the trailing window as provisional rather than as a reading. It is
     * the reason the momentum test below asserts on the interior.
     *
     * @param  array<int, int|float|null>  $values
     * @return array<int, float|null>
     */
    public function baseline(array $values, int $window = 10, float $sigma = 4.0): array
    {
        $values = array_values($values);
        $count = count($values);
        $baseline = [];

        for ($i = 0; $i < $count; $i++) {
            if ($values[$i] === null) {
                $baseline[] = null;

                continue;
            }

            $weightedSum = 0.0;
            $weightTotal = 0.0;

            $from = max(0, $i - $window);
            $to = min($count - 1, $i + $window);

            for ($j = $from; $j <= $to; $j++) {
                if ($values[$j] === null) {
                    continue;
                }

                $weight = exp(-0.5 * (($j - $i) / $sigma) ** 2);
                $weightedSum += $values[$j] * $weight;
                $weightTotal += $weight;
            }

            $baseline[] = $weightTotal > 0 ? round($weightedSum / $weightTotal, 2) : null;
        }

        return $baseline;
    }

    /**
     * Rate of change, and change in the rate of change.
     *
     * Both are smoothed first, because acceleration computed on raw daily
     * values is almost entirely noise: differencing amplifies it, and
     * differencing twice amplifies it twice.
     *
     * @param  array<int, int|float|null>  $values
     * @return array{velocity: array<int, float|null>, acceleration: array<int, float|null>}
     */
    public function momentum(array $values, int $window = 4): array
    {
        $smoothed = $this->baseline($values, $window, max(1.0, $window / 2));

        $velocity = [];
        $acceleration = [];

        foreach ($smoothed as $i => $value) {
            $previous = $smoothed[$i - 1] ?? null;

            $velocity[$i] = ($value !== null && $previous !== null)
                ? round($value - $previous, 3)
                : null;

            $priorVelocity = $velocity[$i - 1] ?? null;

            $acceleration[$i] = ($velocity[$i] !== null && $priorVelocity !== null)
                ? round($velocity[$i] - $priorVelocity, 3)
                : null;
        }

        return ['velocity' => $velocity, 'acceleration' => $acceleration];
    }

    /**
     * Tracked installs as a share of the repository's active installs.
     *
     * The number this whole feature exists to produce, and the one that
     * needs the loudest caveat. active_installs is published in rounded
     * buckets -- 500, 10000, 100000 -- so this is an estimate against a
     * figure that is itself rounded, and a project sitting just under a
     * bucket edge will read far lower than the truth.
     *
     * Null rather than zero when there is nothing to divide by: "no public
     * figure" and "nobody opted in" are entirely different statements.
     */
    public function optInRate(?int $tracked, ?int $publicInstalls): ?float
    {
        if (! $publicInstalls || $tracked === null) {
            return null;
        }

        return round(min(100, $tracked / $publicInstalls * 100), 1);
    }

    /**
     * How long a version took to reach a given share of tracked installs.
     *
     * The plan parked this as unbuildable -- "it needs a release date per
     * version, which nothing records". repo_releases records it now, so it
     * is answerable, with one honest limitation: this measures adoption
     * among sites that opted in, not among everyone.
     *
     * Returns null while a version has not reached the threshold yet, which
     * is not the same as slow adoption and must not render as zero.
     */
    public function daysToAdoption(Project $project, string $version, float $share = 50.0): ?int
    {
        return CurrentAccount::withoutScope(function () use ($project, $version, $share) {
            $release = RepoRelease::acrossAccounts()
                ->where('project_id', $project->id)
                ->where('version', $version)
                ->first();

            if (! $release) {
                return null;
            }

            $stats = DailyStat::acrossAccounts()
                ->where('project_id', $project->id)
                ->whereDate('date', '>=', $release->released_on)
                ->orderBy('date')
                ->get(['date', 'by_version']);

            foreach ($stats as $stat) {
                $counts = $stat->by_version ?? [];
                $total = array_sum($counts);

                if ($total <= 0) {
                    continue;
                }

                if ((($counts[$version] ?? 0) / $total * 100) >= $share) {
                    return (int) $release->released_on->diffInDays($stat->date);
                }
            }

            return null;
        });
    }

    /**
     * Release markers for a chart, keyed by the date they fall on.
     *
     * @param  array<int, string>  $dates  the chart's own labels (Y-m-d)
     * @return array<string, array{version: string, exact: bool}>
     */
    public function releaseMarkers(Project $project, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $releases = CurrentAccount::withoutScope(fn () => RepoRelease::acrossAccounts()
            ->where('project_id', $project->id)
            ->whereBetween('released_on', [reset($dates), end($dates)])
            ->orderBy('released_on')
            ->get());

        $markers = [];

        foreach ($releases as $release) {
            $on = $release->released_on->toDateString();

            /*
             * Two releases on one day is common -- a fix tagged minutes
             * after the release it fixes -- and two markers on one x would
             * overprint into an unreadable smudge. The later version wins
             * the label.
             */
            $markers[$on] = [
                'version' => $release->version,
                'exact' => $release->isExact(),
            ];
        }

        return $markers;
    }

    /**
     * The latest public snapshot, or null if this project has none.
     */
    public function latestSnapshot(Project $project): ?RepoSnapshot
    {
        return CurrentAccount::withoutScope(fn () => RepoSnapshot::acrossAccounts()
            ->where('project_id', $project->id)
            ->orderByDesc('captured_on')
            ->first());
    }
}
