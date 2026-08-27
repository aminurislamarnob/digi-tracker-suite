<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\ProjectDistributionChart;
use App\Filament\Widgets\ProjectHeadlineStats;
use App\Filament\Widgets\ProjectInstallsChart;
use App\Models\DailyStat;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The project overview.
 *
 * Everything on this page reads daily_stats, apart from the two panels
 * explicitly labelled "(live)". That is the rule that keeps a MySQL
 * dashboard fast: aggregating JSON across a quarter of a million
 * site_reports rows on every page load is slow enough to feel broken.
 */
class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function getSubheading(): ?string
    {
        /*
         * Said plainly, on the screen people quote from. Ingest has no
         * authentication -- the protocol has none -- and the hash that routes
         * a payload is visible in GPL source, so anyone holding it can post a
         * heartbeat. "Tracked installs" also counts only sites that opted in,
         * which is why it reads below the wordpress.org figure.
         */
        return 'Counts are claimed, not proven: telemetry is unauthenticated by protocol design. '
            .'Tracked installs counts sites that opted in, so it reads below the wordpress.org figure.';
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $hasRollup = DailyStat::query()->where('project_id', $this->record->id)->exists();

        if (! $hasRollup) {
            // Zeros drawn from an empty rollup look like measurement. They
            // are not, and saying so is cheaper than explaining it later.
            Notification::make()
                ->title('No rollup yet')
                ->body('Charts read the nightly daily_stats table, which has no row for this project. Run telemetry:build-daily-stats.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reports')
                ->label('Reports')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(fn () => ProjectResource::getUrl('reports', ['record' => $this->record])),

            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProjectHeadlineStats::class,
            ProjectInstallsChart::class,
            ProjectDistributionChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }
}
