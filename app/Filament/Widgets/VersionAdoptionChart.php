<?php

namespace App\Filament\Widgets;

use App\Models\DailyStat;
use App\Models\Project;
use Filament\Widgets\ChartWidget;

/**
 * How a release actually lands.
 *
 * Stacked by share rather than by count, because the question is "have
 * people moved" and a raw count conflates adoption with growth: a version
 * can hold flat in absolute terms while its share collapses under a
 * release that is spreading.
 *
 * Reads daily_stats, like everything else on this page.
 */
class VersionAdoptionChart extends ChartWidget
{
    public ?Project $record = null;

    protected ?string $heading = 'Version adoption';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '360px';

    public ?string $filter = '90';

    protected function getFilters(): ?array
    {
        return ['30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'];
    }

    protected function getData(): array
    {
        $series = DailyStat::query()
            ->where('project_id', $this->record->id)
            ->where('date', '>=', now()->subDays((int) $this->filter)->toDateString())
            ->orderBy('date')
            ->get(['date', 'by_version']);

        if ($series->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        // Every version that ever appears in the window, so a version that
        // arrives mid-window still gets a full-length dataset rather than a
        // short one that Chart.js would align against the wrong dates.
        $versions = $series
            ->flatMap(fn ($row) => array_keys($row->by_version ?? []))
            ->unique()
            ->sort(fn ($a, $b) => version_compare($a, $b))
            ->values();

        $palette = ['#98a2b3', '#06aed4', '#7a5af8', '#f79009', '#12b76a', '#6366f1', '#f04438'];

        $datasets = $versions->values()->map(function ($version, $i) use ($series, $palette) {
            return [
                'label' => $version,
                'data' => $series->map(function ($row) use ($version) {
                    $counts = $row->by_version ?? [];
                    $total = array_sum($counts);

                    return $total > 0 ? round(($counts[$version] ?? 0) / $total * 100, 1) : 0;
                })->all(),
                'backgroundColor' => $palette[$i % count($palette)],
                'borderColor' => $palette[$i % count($palette)],
                'fill' => true,
            ];
        });

        return [
            'datasets' => $datasets->all(),
            'labels' => $series->map(fn ($row) => $row->date->format('j M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['stacked' => true, 'max' => 100, 'title' => ['display' => true, 'text' => '% of tracked installs']],
                'x' => ['stacked' => true],
            ],
            'elements' => ['point' => ['radius' => 0]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ];
    }
}
