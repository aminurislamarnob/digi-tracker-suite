<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Services\DashboardMetrics;
use Filament\Widgets\ChartWidget;

class ProjectInstallsChart extends ChartWidget
{
    public ?Project $record = null;

    protected ?string $heading = 'Tracked installs';

    protected ?string $description = 'Sites that opted in, per day, from the nightly rollup.';

    protected int|string|array $columnSpan = 'full';

    // Without a ceiling Chart.js grows to fill the page and one flat line
    // takes up a whole screen.
    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = app(DashboardMetrics::class)->series($this->record);

        return [
            'datasets' => [
                [
                    'label' => 'Tracked installs',
                    'data' => $series->pluck('active_installs')->all(),
                    'borderColor' => '#6366f1',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Deactivations',
                    'data' => $series->pluck('deactivations')->all(),
                    'borderColor' => '#f04438',
                    'backgroundColor' => 'rgba(240, 68, 56, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $series->map(fn ($row) => $row->date->format('j M'))->all(),
        ];
    }
}
