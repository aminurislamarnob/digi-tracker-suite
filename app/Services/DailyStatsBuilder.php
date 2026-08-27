<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Deactivation;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteReport;
use App\Models\TrackingSkip;
use Illuminate\Support\Carbon;

/**
 * Builds the one table every chart is allowed to read.
 *
 * The rule this enforces: dashboards read daily_stats, never site_reports.
 * We chose MySQL, where aggregating JSON across a quarter of a million
 * history rows on every page load is slow enough to make the product feel
 * broken. Doing it once a night costs seconds and makes every chart a
 * primary-key lookup.
 *
 * Everything here is recomputed from history rather than incremented, so
 * any day can be rebuilt after a bug fix without the numbers drifting.
 */
class DailyStatsBuilder
{
    public function buildAll(Carbon $date): int
    {
        $built = 0;

        Project::acrossAccounts()->cursor()->each(function (Project $project) use ($date, &$built) {
            $this->build($project, $date);
            $built++;
        });

        return $built;
    }

    public function build(Project $project, Carbon $date): DailyStat
    {
        $end = $date->copy()->endOfDay();
        $start = $date->copy()->subDays(Site::activeWindowDays() - 1)->startOfDay();

        $activeIds = $this->activeSiteIds($project, $start, $end);

        $attributes = [
            'active_installs' => count($activeIds),
            'new_installs' => $this->newInstalls($project, $date),
            'deactivations' => Deactivation::acrossAccounts()
                ->where('project_id', $project->id)
                ->whereBetween('created_at', [$date->copy()->startOfDay(), $end])
                ->count(),
            'reactivations' => Deactivation::acrossAccounts()
                ->where('project_id', $project->id)
                ->whereBetween('reactivated_at', [$date->copy()->startOfDay(), $end])
                ->count(),
            'skipped' => TrackingSkip::acrossAccounts()
                ->where('project_id', $project->id)
                ->whereBetween('created_at', [$date->copy()->startOfDay(), $end])
                ->count(),
        ];

        // Every first heartbeat is one person who read the notice and said
        // yes, which is exactly what makes it comparable against `skipped`.
        $attributes['opted_in'] = $attributes['new_installs'];

        return DailyStat::acrossAccounts()->updateOrCreate(
            ['project_id' => $project->id, 'date' => $date->toDateString()],
            $attributes + $this->distributions($activeIds, $end) + ['account_id' => $project->account_id],
        );
    }

    /**
     * A site counts as active on a date if it reported inside the trailing
     * window and had not told us it was leaving by the end of that date.
     *
     * Computed from history, not from `sites.status`, because the status
     * column only ever holds today's answer -- asking it about last month
     * would silently return today's number for every date on the chart.
     */
    protected function activeSiteIds(Project $project, Carbon $start, Carbon $end): array
    {
        $reported = SiteReport::acrossAccounts()
            ->where('project_id', $project->id)
            ->whereBetween('reported_at', [$start, $end])
            ->whereIn('site_id', Site::acrossAccounts()
                ->where('project_id', $project->id)
                ->where('is_local', false)
                ->select('id'))
            ->distinct()
            ->pluck('site_id')
            ->all();

        if ($reported === []) {
            return [];
        }

        $gone = Deactivation::acrossAccounts()
            ->where('project_id', $project->id)
            ->whereIn('site_id', $reported)
            ->where('created_at', '<=', $end)
            ->where(fn ($query) => $query
                ->whereNull('reactivated_at')
                ->orWhere('reactivated_at', '>', $end))
            ->distinct()
            ->pluck('site_id')
            ->all();

        return array_values(array_diff($reported, $gone));
    }

    protected function newInstalls(Project $project, Carbon $date): int
    {
        return Site::acrossAccounts()
            ->where('project_id', $project->id)
            ->where('is_local', false)
            ->whereBetween('first_seen_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->count();
    }

    /**
     * One tally pass over each active site's most recent report.
     *
     * Latest-per-site rather than all-reports-in-window: a site that beat
     * five times in the window is one install, and counting every report
     * would weight the noisiest sites most heavily in every distribution.
     */
    protected function distributions(array $activeIds, Carbon $end): array
    {
        $counts = array_fill_keys(DailyStat::DISTRIBUTIONS, []);

        if ($activeIds === []) {
            return $counts;
        }

        $countries = Site::acrossAccounts()
            ->whereIn('id', $activeIds)
            ->pluck('country', 'id');

        $latestIds = SiteReport::acrossAccounts()
            ->whereIn('site_id', $activeIds)
            ->where('reported_at', '<=', $end)
            ->selectRaw('MAX(id) as id')
            ->groupBy('site_id')
            ->pluck('id');

        SiteReport::acrossAccounts()
            ->whereIn('id', $latestIds)
            ->select([
                'id', 'site_id', 'project_version', 'php_version', 'wp_version',
                'mysql_version', 'locale', 'server_software', 'theme_slug', 'multisite',
            ])
            ->chunkById(1000, function ($reports) use (&$counts, $countries) {
                foreach ($reports as $report) {
                    $this->tally($counts['by_version'], $report->project_version);
                    $this->tally($counts['by_php'], $this->minorVersion($report->php_version));
                    $this->tally($counts['by_wp'], $this->minorVersion($report->wp_version));
                    $this->tally($counts['by_mysql'], $this->minorVersion($report->mysql_version));
                    $this->tally($counts['by_locale'], $report->locale);
                    $this->tally($counts['by_server'], $this->serverFamily($report->server_software));
                    $this->tally($counts['by_theme'], $report->theme_slug);
                    $this->tally($counts['by_multisite'], $report->multisite ? 'yes' : 'no');
                    $this->tally($counts['by_country'], $countries[$report->site_id] ?? null);
                }
            });

        foreach ($counts as $key => $tally) {
            arsort($tally);
            $counts[$key] = $tally;
        }

        return $counts;
    }

    protected function tally(array &$counts, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    /**
     * "Can we drop PHP 7.4?" is a question about 7.4, not about 7.4.33.
     * Grouping at the minor version turns a chart of two hundred slivers
     * into one somebody can act on.
     */
    protected function minorVersion(?string $version): ?string
    {
        if (! $version) {
            return null;
        }

        return preg_match('/^(\d+\.\d+)/', $version, $m) ? $m[1] : $version;
    }

    /**
     * Server strings carry a build number and often a module list, which
     * makes every host its own category. The family is the useful part.
     */
    protected function serverFamily(?string $software): ?string
    {
        if (! $software) {
            return null;
        }

        return strstr($software, '/', true) ?: $software;
    }
}
