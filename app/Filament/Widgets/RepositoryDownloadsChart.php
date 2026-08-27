<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\RepoDownload;
use App\Services\RepoAnalytics;
use App\Support\CurrentAccount;
use Filament\Widgets\ChartWidget;

/**
 * Downloads from wordpress.org, with the two lines that make them readable
 * and the markers that make them explicable.
 *
 * Raw daily downloads are close to unreadable on their own -- weekday
 * rhythm alone swamps the signal -- so the series carries a regression
 * trend and a smoothed baseline over it. The release markers are the part
 * that turns the chart from a picture into an answer: a dip means nothing
 * until you can see the version that shipped the day before it.
 *
 * The markers are a thin bar dataset rather than Chart.js annotations,
 * because Filament ships Chart.js without the annotation plugin and pulling
 * a second charting dependency into the panel to draw vertical lines is a
 * poor trade.
 */
class RepositoryDownloadsChart extends ChartWidget
{
    public ?Project $record = null;

    protected ?string $heading = 'Downloads from wordpress.org';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '380px';

    public ?string $filter = '180';

    protected function getFilters(): ?array
    {
        return [
            '90' => 'Last 90 days',
            '180' => 'Last 6 months',
            '365' => 'Last year',
            '730' => 'Last 2 years',
        ];
    }

    public function getDescription(): ?string
    {
        return 'Public download counts, not installs. The dashed line is a smoothed '
            .'baseline; vertical marks are releases. The most recent few days are '
            .'provisional -- wordpress.org revises them as mirrors report in.';
    }

    protected function getData(): array
    {
        if (! $this->record?->isOnRepository()) {
            return ['datasets' => [], 'labels' => []];
        }

        $rows = CurrentAccount::withoutScope(fn () => RepoDownload::acrossAccounts()
            ->where('project_id', $this->record->id)
            ->where('date', '>=', now()->subDays((int) $this->filter)->toDateString())
            ->orderBy('date')
            ->get(['date', 'downloads']));

        if ($rows->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        $labels = $rows->map(fn ($row) => $row->date->toDateString())->all();
        $values = $rows->map(fn ($row) => (int) $row->downloads)->all();

        $analytics = app(RepoAnalytics::class);

        $trend = $analytics->trend($values);
        $baseline = $analytics->baseline($values);
        $markers = $analytics->releaseMarkers($this->record, $labels);

        $trendColour = match ($trend['direction']) {
            'rising' => '#16a34a',
            'falling' => '#dc2626',
            default => '#6b7280',
        };

        /*
         * Markers are drawn to the top of the plot, so they need a height.
         * Taken from the peak rather than a round number, so a quiet plugin
         * does not get marks running off the top of its own chart.
         */
        $ceiling = max($values) ?: 1;

        $releaseBars = [];
        $releaseLabels = [];

        foreach ($labels as $label) {
            $releaseBars[] = isset($markers[$label]) ? $ceiling : null;
            $releaseLabels[] = $markers[$label]['version'] ?? null;
        }

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Release',
                    'data' => $releaseBars,
                    'backgroundColor' => 'rgba(220,38,38,0.35)',
                    'barThickness' => 2,
                    'order' => 3,
                    // Carried through so the tooltip can name the version.
                    'releaseVersions' => $releaseLabels,
                ],
                [
                    'type' => 'line',
                    'label' => 'Daily downloads',
                    'data' => $values,
                    'borderColor' => 'rgba(25,92,227,0.55)',
                    'backgroundColor' => 'rgba(25,92,227,0.08)',
                    'borderWidth' => 1,
                    'pointRadius' => 0,
                    'fill' => true,
                    'tension' => 0.3,
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => 'Baseline',
                    'data' => $baseline,
                    'borderColor' => '#9ca3af',
                    'borderDash' => [6, 4],
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'fill' => false,
                    'order' => 1,
                ],
                [
                    'type' => 'line',
                    'label' => 'Trend ('.$trend['direction'].')',
                    'data' => $trend['values'],
                    'borderColor' => $trendColour,
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'fill' => false,
                    'order' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
                'x' => [
                    'ticks' => ['maxTicksLimit' => 12, 'maxRotation' => 0],
                    'grid' => ['display' => false],
                ],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 12]],
            ],
        ];
    }
}
