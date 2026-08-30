<?php

namespace App\Filament\Widgets;

use App\Services\AccountOverview;
use Filament\Widgets\ChartWidget;

/**
 * Every project's tracked installs, added together, per day.
 *
 * Installs on the left axis and deactivations on the right, because they
 * differ by two orders of magnitude: on one axis the deactivation line is
 * a flat smear along the bottom and the only thing it can tell you --
 * whether it moved -- is the one thing it cannot show.
 */
class AccountInstallsChart extends ChartWidget
{
    protected ?string $heading = 'Tracked installs, all projects';

    protected ?string $description = 'Summed across every active project, from the nightly rollup. A day the rollup never ran is a gap rather than a zero.';

    protected int|string|array $columnSpan = 'full';

    // Without a ceiling Chart.js grows to fill the page and one flat line
    // takes a whole screen.
    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * Hidden entirely rather than drawn empty.
     *
     * An axis with no line reads as "zero installs"; the page says "no
     * rollup yet" in its place, which is the actual state.
     */
    public static function canView(): bool
    {
        return app(AccountOverview::class)->series()->isNotEmpty();
    }

    protected function getData(): array
    {
        $series = app(AccountOverview::class)->series();

        return [
            'datasets' => [
                [
                    'label' => 'Tracked installs',
                    'data' => $series->pluck('active_installs')->all(),
                    'borderColor' => '#195CE3',
                    'backgroundColor' => 'rgba(25, 92, 227, 0.10)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    // Points only on hover: at ninety days a marker per day
                    // is a dotted rope, not a line.
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'yAxisID' => 'installs',
                ],
                [
                    'label' => 'Deactivations',
                    'data' => $series->pluck('deactivations')->all(),
                    'borderColor' => '#f04438',
                    'backgroundColor' => 'rgba(240, 68, 56, 0.08)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'yAxisID' => 'churn',
                ],
            ],
            'labels' => $series->map(fn ($row) => $row->date->format('j M'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'interaction' => [
                // One tooltip listing both lines for the day under the
                // cursor, rather than whichever line the pointer is nearest.
                'mode' => 'index',
                'intersect' => false,
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => ['usePointStyle' => true, 'boxWidth' => 6, 'padding' => 16],
                ],
            ],
            'scales' => [
                'installs' => [
                    'type' => 'linear',
                    'position' => 'left',
                    'beginAtZero' => true,
                    'border' => ['display' => false],
                    'ticks' => ['maxTicksLimit' => 5],
                ],
                'churn' => [
                    'type' => 'linear',
                    'position' => 'right',
                    'beginAtZero' => true,
                    'border' => ['display' => false],
                    // The left axis owns the horizontal rules; a second set
                    // half a pixel off the first is visual noise.
                    'grid' => ['drawOnChartArea' => false],
                    'ticks' => ['maxTicksLimit' => 5],
                ],
                'x' => [
                    'grid' => ['display' => false],
                    'border' => ['display' => false],
                    'ticks' => ['maxTicksLimit' => 12, 'autoSkip' => true],
                ],
            ],
        ];
    }
}
