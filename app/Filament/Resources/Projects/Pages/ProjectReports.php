<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\ChurnByThemeChart;
use App\Filament\Widgets\NeighbourPluginsChart;
use App\Filament\Widgets\VersionAdoptionChart;
use App\Models\DailyStat;
use App\Models\Site;
use BackedEnum;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The questions the overview cannot answer at a glance.
 *
 * Overview is "how are we doing". This is "what should we do": which
 * plugins to test against, which themes are costing us installs, whether
 * a release has landed, and who is stuck behind.
 */
class ProjectReports extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'Reports';

    protected string $view = 'filament.resources.projects.pages.project-reports';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getSubheading(): ?string
    {
        return $this->record->name;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            VersionAdoptionChart::class,
            NeighbourPluginsChart::class,
            ChurnByThemeChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * Sites still running something other than the newest version we have
     * seen -- the concrete form of "who is stuck behind".
     *
     * Newest is taken from what sites actually report, not from a release
     * we were told about: the latest version on wordpress.org is not
     * necessarily the latest version anybody is running.
     *
     * @return array<string, mixed>
     */
    public function getLaggards(): array
    {
        $latest = DailyStat::query()
            ->where('project_id', $this->record->id)
            ->orderByDesc('date')
            ->value('by_version') ?? [];

        if ($latest === []) {
            return ['newest' => null, 'behind' => collect(), 'total' => 0];
        }

        $versions = collect(array_keys($latest))->sort(fn ($a, $b) => version_compare($a, $b));
        $newest = $versions->last();

        $behind = collect($latest)
            ->forget($newest)
            ->filter()
            ->sortKeysDesc();

        return [
            'newest' => $newest,
            'newestShare' => array_sum($latest) > 0
                ? round(($latest[$newest] ?? 0) / array_sum($latest) * 100)
                : 0,
            'behind' => $behind,
            'total' => $behind->sum(),
        ];
    }

    /**
     * Sites that have gone quiet without ever saying they were leaving.
     * Not churn -- more often a broken wp-cron -- but it caps how much the
     * install count can be trusted, so it belongs on the same page.
     */
    public function getSilentCount(): int
    {
        return Site::query()
            ->where('project_id', $this->record->id)
            ->where('status', Site::STATUS_INACTIVE)
            ->where('is_local', false)
            ->count();
    }
}
