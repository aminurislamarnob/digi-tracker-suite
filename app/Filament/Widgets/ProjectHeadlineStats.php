<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Services\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The three numbers at the top of a project.
 *
 * Everything here comes from DashboardMetrics, which reads daily_stats and
 * nothing else. If a number is missing from this widget the fix is a
 * column in the nightly rollup, never a query added here.
 */
class ProjectHeadlineStats extends StatsOverviewWidget
{
    public ?Project $record = null;

    protected function getStats(): array
    {
        $headline = app(DashboardMetrics::class)->headline($this->record);

        return collect($headline)
            ->map(fn (array $card) => $this->toStat($card))
            ->values()
            ->all();
    }

    protected function toStat(array $card): Stat
    {
        $value = number_format($card['value'], is_float($card['value']) ? 1 : 0).($card['suffix'] ?? '');

        $spark = array_values(array_filter($card['spark'], fn ($point) => $point !== null));

        $stat = Stat::make($card['label'], $value)->chart($spark ?: [0]);

        if ($card['delta'] === null) {
            /*
             * No prior period to compare against. Rendering "+100%" for a
             * project's first week would be reporting growth we never
             * measured, so the description says so instead.
             */
            return $stat->description('no earlier period')->color('gray');
        }

        // Churn going up is bad news; installs going up is good. Same arrow,
        // opposite meaning, so the colour has to follow the metric.
        $good = ($card['inverted'] ?? false) ? $card['delta'] <= 0 : $card['delta'] >= 0;

        return $stat
            ->description(($card['delta'] > 0 ? '+' : '').$card['delta'].'% on the previous period')
            ->descriptionIcon($card['delta'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($good ? 'success' : 'danger')
            ->chartColor($good ? 'success' : 'danger');
    }
}
