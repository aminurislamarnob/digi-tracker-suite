<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\RepositoryDownloadsChart;
use App\Models\DailyStat;
use App\Models\RepoRanking;
use App\Services\RepoAnalytics;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The public half of the picture, next to ours.
 *
 * Everywhere else in this panel reports what opted-in sites told us. This
 * page reports what wordpress.org shows the world, and the reason to put
 * them on one screen is the number in the middle: tracked installs over
 * public active installs is the opt-in rate, which until this data existed
 * the dashboard could only gesture at.
 *
 * It is also the honest counterweight to every other figure here. If the
 * opted-in population runs noticeably newer versions than the repository's
 * own split, our numbers describe enthusiasts rather than users, and that
 * is worth knowing before quoting any of them.
 */
class ProjectRepository extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $title = 'Repository';

    protected string $view = 'filament.resources.projects.pages.project-repository';

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
        return [RepositoryDownloadsChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * The headline trio: what the repository claims, what we measure, and
     * the ratio between them.
     *
     * @return array<string, mixed>
     */
    public function getHeadline(): array
    {
        $analytics = app(RepoAnalytics::class);
        $snapshot = $analytics->latestSnapshot($this->record);

        $tracked = CurrentAccount::withoutScope(fn () => DailyStat::acrossAccounts()
            ->where('project_id', $this->record->id)
            ->orderByDesc('date')
            ->value('active_installs'));

        return [
            'linked' => $this->record->isOnRepository(),
            'snapshot' => $snapshot,
            'publicInstalls' => $snapshot?->active_installs,
            'tracked' => $tracked,
            'optInRate' => $analytics->optInRate($tracked, $snapshot?->active_installs),
            'rating' => $snapshot?->rating,
            'numRatings' => $snapshot?->num_ratings,
            'resolutionRate' => $snapshot?->resolutionRate(),
            'supportThreads' => $snapshot?->support_threads,
        ];
    }

    /**
     * The repository's version split beside ours.
     *
     * Theirs is a percentage of every install; ours is a count of the sites
     * that opted in. They are not the same measurement and the view says so
     * -- but read together, a wide gap is the clearest signal available that
     * our sample is not representative.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVersionComparison(): array
    {
        $snapshot = app(RepoAnalytics::class)->latestSnapshot($this->record);

        $public = $snapshot?->version_distribution ?? [];

        $ours = CurrentAccount::withoutScope(fn () => DailyStat::acrossAccounts()
            ->where('project_id', $this->record->id)
            ->orderByDesc('date')
            ->value('by_version')) ?? [];

        $ourTotal = array_sum($ours);

        /*
         * The repository reports minor lines -- "3.1" covers 3.1.4 and
         * 3.1.7 alike -- while telemetry reports exact versions. Ours are
         * folded to the same precision, otherwise every row would read as
         * present on one side and absent on the other.
         */
        $folded = [];

        foreach ($ours as $version => $count) {
            $parts = explode('.', (string) $version);
            $line = implode('.', array_slice($parts, 0, 2));
            $folded[$line] = ($folded[$line] ?? 0) + $count;
        }

        $lines = collect(array_keys($public))
            ->merge(array_keys($folded))
            ->reject(fn ($line) => $line === 'other')
            ->unique()
            ->sort(fn ($a, $b) => version_compare($b, $a))
            ->values();

        return $lines->map(fn ($line) => [
            'version' => $line,
            'publicShare' => isset($public[$line]) ? round((float) $public[$line], 1) : null,
            'ourShare' => $ourTotal > 0 && isset($folded[$line])
                ? round($folded[$line] / $ourTotal * 100, 1)
                : null,
            'ourCount' => $folded[$line] ?? 0,
        ])->all();
    }

    /**
     * Release history, newest first, with how long each took to reach half
     * of tracked installs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReleases(): array
    {
        $analytics = app(RepoAnalytics::class);

        return CurrentAccount::withoutScope(fn () => $this->record->repoReleases()
            ->orderByDesc('released_on')
            ->limit(15)
            ->get())
            ->map(fn ($release) => [
                'version' => $release->version,
                'releasedOn' => $release->released_on,
                'exact' => $release->isExact(),
                // Null means "not there yet", which is not the same as slow
                // and must not render as a zero.
                'daysToHalf' => $analytics->daysToAdoption($this->record, $release->version),
            ])
            ->all();
    }

    /**
     * Today's search position per keyword, with the change since a week ago.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRankings(): array
    {
        return CurrentAccount::withoutScope(function () {
            $keywords = $this->record->repoKeywords()->where('is_active', true)->orderBy('keyword')->get();

            return $keywords->map(function ($keyword) {
                $latest = RepoRanking::acrossAccounts()
                    ->where('repo_keyword_id', $keyword->id)
                    ->orderByDesc('captured_on')
                    ->first();

                $prior = RepoRanking::acrossAccounts()
                    ->where('repo_keyword_id', $keyword->id)
                    ->whereDate('captured_on', '<=', now()->subWeek()->toDateString())
                    ->orderByDesc('captured_on')
                    ->first();

                /*
                 * A move is only meaningful between two real positions.
                 * Entering or leaving the window is a change of kind, not
                 * of degree, and subtracting a null would invent a number.
                 */
                $movement = ($latest?->position !== null && $prior?->position !== null)
                    ? $prior->position - $latest->position
                    : null;

                return [
                    'keyword' => $keyword->keyword,
                    'position' => $latest?->position,
                    'depth' => $latest?->searched_depth,
                    'total' => $latest?->total_results,
                    'movement' => $movement,
                    'capturedOn' => $latest?->captured_on,
                ];
            })->all();
        });
    }
}
