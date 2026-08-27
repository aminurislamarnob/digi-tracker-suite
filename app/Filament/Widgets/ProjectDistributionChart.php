<?php

namespace App\Filament\Widgets;

use App\Models\DeactivationReason;
use App\Models\Project;
use App\Models\Site;
use App\Services\DashboardMetrics;
use Filament\Widgets\ChartWidget;

/**
 * One doughnut with a picker, rather than eight fixed ones.
 *
 * The plan called for a wall of donuts. In practice somebody looking at
 * this page has one question at a time -- "can we drop PHP 8.0?", "where
 * are these installs?" -- and a picker answers it without the other seven
 * charts competing for the same glance.
 */
class ProjectDistributionChart extends ChartWidget
{
    public ?Project $record = null;

    protected ?string $heading = 'Distribution';

    protected int|string|array $columnSpan = 'full';

    // Without a ceiling Chart.js grows to fill the page and one flat line
    // takes up a whole screen.
    protected ?string $maxHeight = '320px';

    public ?string $filter = 'by_php';

    /** Everything the nightly rollup knows how to break down. */
    protected function getFilters(): ?array
    {
        return [
            'by_php' => 'PHP version',
            'by_wp' => 'WordPress version',
            'by_version' => 'Plugin version',
            'by_mysql' => 'MySQL version',
            'by_server' => 'Server software',
            'by_locale' => 'Locale',
            'by_country' => 'Country',
            'by_multisite' => 'Multisite',
            'status' => 'Site status (live)',
            'reasons' => 'Deactivation reasons (live)',
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * Chart.js draws nothing at all for an empty dataset, which reads as a
     * broken widget rather than as an honest "we have not measured this".
     */
    public function getDescription(): ?string
    {
        return $this->counts() === []
            ? 'Nothing recorded for this breakdown yet.'
            : null;
    }

    protected function getData(): array
    {
        $counts = $this->counts();

        return [
            'datasets' => [[
                'data' => array_values($counts),
                'backgroundColor' => [
                    '#6366f1', '#12b76a', '#f79009', '#f04438',
                    '#7a5af8', '#06aed4', '#98a2b3',
                ],
                'borderWidth' => 0,
            ]],
            'labels' => array_keys($counts),
        ];
    }

    protected function counts(): array
    {
        return match ($this->filter) {
            'status' => $this->statusCounts(),
            'reasons' => $this->reasonCounts(),
            default => app(DashboardMetrics::class)->distribution($this->record, $this->filter),
        };
    }

    /**
     * The two "(live)" breakdowns read current state rather than the rollup,
     * because they answer "what is true now" -- a nightly figure would be
     * stale by design. Both are indexed counts, not JSON aggregates.
     */
    protected function statusCounts(): array
    {
        $counts = Site::query()
            ->where('project_id', $this->record->id)
            ->where('is_local', false)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_filter([
            'Active' => (int) $counts->get(Site::STATUS_ACTIVE, 0),
            'Inactive' => (int) $counts->get(Site::STATUS_INACTIVE, 0),
            'Deactivated' => (int) $counts->get(Site::STATUS_DEACTIVATED, 0),
        ]);
    }

    protected function reasonCounts(): array
    {
        $labels = DeactivationReason::query()
            ->where('project_id', $this->record->id)
            ->pluck('label', 'reason_id');

        return $this->record->deactivations()
            ->selectRaw('reason_id, COUNT(*) as total')
            ->groupBy('reason_id')
            ->pluck('total', 'reason_id')
            ->mapWithKeys(fn ($total, $id) => [$labels[$id] ?? ($id ?: 'Not given') => (int) $total])
            ->sortDesc()
            ->all();
    }
}
