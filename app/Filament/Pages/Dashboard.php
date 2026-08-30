<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AccountHeadlineStats;
use App\Filament\Widgets\AccountInstallsChart;
use App\Filament\Widgets\AccountProjectsTable;
use App\Filament\Widgets\ProjectsNeedingAttention;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * Where signing in lands.
 *
 * Until this existed the panel opened on the projects table, which answers
 * "what have I got" and nothing else -- every actual question needed a
 * click into one project, and questions spanning projects had nowhere to
 * be asked at all.
 *
 * The order is the order the questions arrive in: how are we doing, what
 * shape is it, is anything wrong, and then the per-project detail. The
 * attention list sits third rather than first on purpose. Opening every
 * visit with a list of problems trains people to scroll past it, and on
 * most days it is empty and renders nothing.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return 'Dashboard';
    }

    /**
     * The account's name, not "Dashboard" again.
     *
     * The heading, the breadcrumb and the sidebar item would otherwise all
     * say the same word, which spends the most prominent line on the page
     * telling somebody where they already know they are.
     */
    public function getHeading(): string
    {
        return Filament::getTenant()?->name ?? 'Dashboard';
    }

    public function getSubheading(): ?string
    {
        return 'Telemetry from sites that opted in, beside the public record on wordpress.org.';
    }

    public function getWidgets(): array
    {
        return [
            AccountHeadlineStats::class,
            AccountInstallsChart::class,
            ProjectsNeedingAttention::class,
            AccountProjectsTable::class,
        ];
    }

    /**
     * One column, and every widget full width.
     *
     * The stats row and the chart both want the whole width -- four tiles
     * squeezed into half a screen wrap to two lines, and a ninety-day
     * series in a half-width box is a smudge. Filament's default of two
     * columns would give both.
     */
    public function getColumns(): int|array
    {
        return 1;
    }
}
