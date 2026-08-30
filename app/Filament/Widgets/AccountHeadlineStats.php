<?php

namespace App\Filament\Widgets;

use App\Services\AccountOverview;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four figures the dashboard opens with.
 *
 * Shaped like ProjectHeadlineStats on purpose -- same tile, same delta
 * wording, same colour rule -- because the account dashboard and a project
 * dashboard being visibly the same kind of screen is most of what makes
 * either legible.
 *
 * Two of the four are marked quiet: they carry no delta and no sparkline.
 * A trend line under a count of projects would be four points of
 * decoration, and lifetime downloads only ever goes up, so an arrow beside
 * it says nothing a reader did not already assume.
 */
class AccountHeadlineStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        return collect(app(AccountOverview::class)->headline())
            ->map(fn (array $card) => $this->toStat($card))
            ->values()
            ->all();
    }

    protected function toStat(array $card): Stat
    {
        $stat = Stat::make($card['label'], $this->format($card));

        if ($card['quiet'] ?? false) {
            return $stat->description($card['description'])->color('gray');
        }

        $spark = array_values(array_filter($card['spark'] ?? [], fn ($point) => $point !== null));

        $stat = $stat->chart($spark ?: [0]);

        if ($card['delta'] === null) {
            // No prior period. "+100%" for a first week would be reporting
            // growth nobody measured.
            return $stat->description($card['description'])->color('gray');
        }

        // Churn rising is bad news, installs rising is good. Same arrow,
        // opposite meaning, so the colour follows the metric.
        $good = ($card['inverted'] ?? false) ? $card['delta'] <= 0 : $card['delta'] >= 0;

        return $stat
            ->description(($card['delta'] > 0 ? '+' : '').$card['delta'].'% on the previous period')
            ->descriptionIcon($card['delta'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($good ? 'success' : 'danger')
            ->chartColor($good ? 'success' : 'danger');
    }

    /**
     * A dash for null, never a zero.
     *
     * Nothing captured yet and nothing downloaded are different facts, and
     * only one of them is about wordpress.org.
     */
    protected function format(array $card): string
    {
        if ($card['value'] === null) {
            return '—';
        }

        return number_format($card['value'], is_float($card['value']) ? 1 : 0).($card['suffix'] ?? '');
    }
}
