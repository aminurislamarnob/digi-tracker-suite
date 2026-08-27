<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\Site;
use App\Models\SitePlugin;
use Filament\Widgets\ChartWidget;

/**
 * What runs alongside us.
 *
 * The most sensitive thing this platform collects, and the reason the
 * plugin inventory is aggregate-only: a per-site plugin list is a
 * fingerprint of somebody's business and, read the wrong way, an inventory
 * of their attack surface. Counted across sites it is just a compatibility
 * map -- "we should test against WooCommerce" -- which is worth having.
 *
 * Nothing here can reach a single site, and no export includes it.
 */
class NeighbourPluginsChart extends ChartWidget
{
    public ?Project $record = null;

    protected ?string $heading = 'Most used plugins alongside ours';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '360px';

    public function getDescription(): ?string
    {
        return 'Counted across active installs. Never browsable per site, never exported.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = SitePlugin::query()
            ->where('site_plugins.project_id', $this->record->id)
            // Only sites we still consider live, or the chart slowly fills
            // with the plugin lists of installs that left months ago.
            ->whereIn('site_id', Site::query()
                ->where('project_id', $this->record->id)
                ->where('status', Site::STATUS_ACTIVE)
                ->where('is_local', false)
                ->select('id'))
            ->selectRaw('slug, MAX(name) as name, COUNT(*) as total')
            ->groupBy('slug')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        return [
            'datasets' => [[
                'label' => 'Sites',
                'data' => $counts->pluck('total')->all(),
                'backgroundColor' => '#6366f1',
                'borderRadius' => 4,
            ]],
            'labels' => $counts->map(fn ($row) => $row->name ?: $row->slug)->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        ];
    }
}
