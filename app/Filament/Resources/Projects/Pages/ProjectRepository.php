<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\RepositoryDownloadsChart;
use App\Filament\Widgets\RepositoryDownloadsSummary;
use App\Jobs\RefreshRepoStats;
use App\Models\DailyStat;
use App\Models\RepoRanking;
use App\Services\RepoAnalytics;
use App\Services\RepoDownloads;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Fetch the public record now, without waiting for 03:00.
     *
     * The work is queued rather than done here -- see RefreshRepoStats for
     * why -- so the honest thing to report is that it has been asked for,
     * not that it is done. Saying "refreshed" and rendering the same
     * numbers would be worse than saying nothing.
     *
     * Throttled with the same window RepoDownloads caches over, because
     * inside that window a second fetch cannot change anything on screen.
     * The guard is a cache key rather than a disabled button: two people
     * looking at one project each hold their own page state, and only a
     * shared one can stop the second press.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from wordpress.org')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => $this->record->isOnRepository() && ! $this->record->is_demo)
                ->action(function () {
                    $key = RefreshRepoStats::cooldownKey($this->record->id);

                    if (Cache::has($key)) {
                        Notification::make()
                            ->title('Refreshed recently')
                            ->body('The public record was fetched within the last '
                                .(int) round(RefreshRepoStats::COOLDOWN_SECONDS / 60)
                                .' minutes. wordpress.org will not have moved much, and the nightly capture runs at 03:00.')
                            ->warning()
                            ->send();

                        return;
                    }

                    /*
                     * Claimed before dispatch, not after. A queued job may
                     * not start for a while, and a window in which the
                     * button still works is a window in which an impatient
                     * click queues a second identical fetch.
                     */
                    Cache::put($key, true, RefreshRepoStats::COOLDOWN_SECONDS);

                    RefreshRepoStats::dispatch($this->record->id);

                    Notification::make()
                        ->title('Refresh queued')
                        ->body("Fetching the public record for '{$this->record->wporg_slug}'. Reload in a moment to see it.")
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [RepositoryDownloadsSummary::class, RepositoryDownloadsChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * Conversion bands.
     *
     * A rule of thumb, and labelled as one in the view. Nobody publishes
     * what a good download-to-install ratio is, so these thresholds are
     * ours -- stated here rather than buried in a ternary, because a number
     * that carries a verdict needs the verdict to be inspectable.
     *
     * @var array<int, array{float, string, string}>
     */
    protected const CONVERSION_BANDS = [
        [10.0, 'very good', 'success'],
        [5.0, 'good', 'success'],
        [2.0, 'fair', 'warning'],
        [0.0, 'low', 'danger'],
    ];

    /**
     * The four public figures, as the repository reports them.
     *
     * Downloads, installs, rating, and the ratio between the first two.
     * Nothing on these cards comes from telemetry -- which is the point of
     * them leading the page. Telemetry starts at zero for every project and
     * stays there until a release carrying the SDK reaches real sites; the
     * repository has been publishing all along.
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

        $downloads = app(RepoDownloads::class)->summary($this->record);
        $installs = $snapshot?->active_installs;

        return [
            'linked' => $this->record->isOnRepository(),
            'snapshot' => $snapshot,
            'downloads' => $downloads['allTime'],
            'downloadsYesterday' => $downloads['yesterday'],
            'publicInstalls' => $installs,
            'tracked' => $tracked,
            'optInRate' => $analytics->optInRate($tracked, $installs),
            'rating' => $snapshot?->rating,
            'numRatings' => $snapshot?->num_ratings,
            'resolutionRate' => $snapshot?->resolutionRate(),
            'supportThreads' => $snapshot?->support_threads,
            ...$this->getConversion($installs, $downloads['allTime']),
        ];
    }

    /**
     * Installs over downloads.
     *
     * Read as an install rate it understates badly, and knowing why matters
     * before quoting it: the daily series counts every update every
     * existing site pulls, not just first installs, so an established
     * plugin is dividing by a number that grows with its own success. The
     * trend in it still says something even where the level does not, which
     * is the case for showing it -- with the view saying what it is rather
     * than calling it a conversion rate full stop.
     *
     * @return array{conversion: ?float, conversionLabel: ?string, conversionColour: ?string}
     */
    protected function getConversion(?int $installs, ?int $downloads): array
    {
        // Null, never 0%: with either half missing there is no ratio at
        // all, which is not the same as a ratio of nothing.
        if (! $installs || ! $downloads) {
            return ['conversion' => null, 'conversionLabel' => null, 'conversionColour' => null];
        }

        /*
         * Capped, for the same reason the opt-in rate is: active_installs
         * is published in rounded buckets, so a plugin sitting just over a
         * bucket edge can report more installs than it has ever had
         * downloads. 224% is not a finding, it is an artefact of the
         * rounding -- and printed on a card it would discredit every other
         * figure beside it.
         */
        $rate = round(min(100, $installs / $downloads * 100), 2);

        foreach (self::CONVERSION_BANDS as [$floor, $label, $colour]) {
            if ($rate >= $floor) {
                return ['conversion' => $rate, 'conversionLabel' => $label, 'conversionColour' => $colour];
            }
        }

        return ['conversion' => $rate, 'conversionLabel' => null, 'conversionColour' => null];
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
