<?php

namespace App\Filament\Widgets;

use App\Models\Deactivation;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteReport;
use Filament\Widgets\ChartWidget;

/**
 * Which themes we churn from most.
 *
 * A raw count is misleading: the most popular theme will top the list
 * simply by being the most popular. What matters is the rate -- of the
 * sites running this theme, what share left -- because that is what points
 * at a genuine incompatibility rather than at market share.
 *
 * Themes with too few installs to mean anything are dropped, or a single
 * deactivation on a theme with two users reads as a 50% churn rate and
 * sits at the top of the chart forever.
 */
class ChurnByThemeChart extends ChartWidget
{
    public ?Project $record = null;

    protected ?string $heading = 'Themes we churn from most';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '360px';

    /** Below this, the rate is noise. */
    protected const MIN_INSTALLS = 5;

    public function getDescription(): ?string
    {
        return 'Share of sites on each theme that deactivated. Themes under '
            .self::MIN_INSTALLS.' installs are excluded as too small to read.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $churned = Deactivation::query()
            ->where('project_id', $this->record->id)
            ->whereNotNull('theme_slug')
            ->selectRaw('theme_slug, COUNT(DISTINCT site_id) as total')
            ->groupBy('theme_slug')
            ->pluck('total', 'theme_slug');

        if ($churned->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        // The denominator: every site we have ever seen on that theme, from
        // its most recent report.
        $seen = SiteReport::query()
            ->whereIn('id', SiteReport::query()
                ->where('project_id', $this->record->id)
                ->whereIn('site_id', Site::query()
                    ->where('project_id', $this->record->id)
                    ->where('is_local', false)
                    ->select('id'))
                ->selectRaw('MAX(id) as id')
                ->groupBy('site_id'))
            ->whereIn('theme_slug', $churned->keys())
            ->selectRaw('theme_slug, COUNT(*) as total')
            ->groupBy('theme_slug')
            ->pluck('total', 'theme_slug');

        $rates = $churned
            ->mapWithKeys(function ($left, $slug) use ($seen) {
                $total = (int) $seen->get($slug, 0);

                return [$slug => $total >= self::MIN_INSTALLS ? round($left / $total * 100, 1) : null];
            })
            ->filter()
            ->sortDesc()
            ->take(12);

        return [
            'datasets' => [[
                'label' => '% of installs on this theme that left',
                'data' => $rates->values()->all(),
                'backgroundColor' => '#f04438',
                'borderRadius' => 4,
            ]],
            'labels' => $rates->keys()->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['max' => 100, 'ticks' => ['callback' => null]]],
        ];
    }
}
