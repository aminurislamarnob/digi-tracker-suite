<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

/**
 * Today, yesterday, the week and all time -- as wordpress.org states them.
 *
 * Deliberately not derived from our own repo_downloads table, even though
 * three of the four could be. Two reasons, and both produce wrong answers
 * that look right:
 *
 * - Our daily series is refreshed once a night. "Today" read from it would
 *   be whatever the figure was at 03:00, which is close to nothing, and it
 *   would sit there being wrong all day.
 * - The daily endpoint caps at 730 days, so summing it undercounts every
 *   plugin older than two years. wp-change-email-sender reads 57,988 that
 *   way against a true 78,203 -- a quarter of its history missing, with
 *   nothing on screen to suggest anything is off.
 *
 * So the figures come live from the summary endpoint, cached briefly, with
 * the last snapshot as the fallback. A wordpress.org outage costs
 * freshness, never the page.
 */
class RepoDownloads
{
    /**
     * Short enough that "today" is genuinely today, long enough that a
     * dashboard left open on a wall is not hammering a public API we are a
     * guest on.
     */
    public const CACHE_SECONDS = 900;

    public function __construct(
        protected WordPressOrg $repository,
        protected RepoAnalytics $analytics,
    ) {}

    /**
     * @return array{today: ?int, yesterday: ?int, lastWeek: ?int, allTime: ?int, live: bool}
     *                                                                                        `live` is false when the numbers came from the last stored
     *                                                                                        snapshot rather than from wordpress.org just now.
     */
    public function summary(Project $project): array
    {
        if (! $project->isOnRepository()) {
            return $this->none();
        }

        $summary = Cache::remember(
            "wporg:downloads:{$project->wporg_slug}",
            self::CACHE_SECONDS,
            fn () => $this->repository->downloadSummary($project->wporg_slug),
        );

        if ($summary !== null) {
            return [
                'today' => $summary['today'],
                'yesterday' => $summary['yesterday'],
                'lastWeek' => $summary['last_week'],
                'allTime' => $summary['all_time'],
                'live' => true,
            ];
        }

        /*
         * Never cache the failure. A miss caches nothing, so the next page
         * load tries again -- fifteen minutes of stale numbers after a
         * blip would be a self-inflicted outage.
         */
        Cache::forget("wporg:downloads:{$project->wporg_slug}");

        $snapshot = $this->analytics->latestSnapshot($project);

        if ($snapshot === null) {
            return $this->none();
        }

        return [
            'today' => $snapshot->downloads_today,
            'yesterday' => $snapshot->downloads_yesterday,
            'lastWeek' => $snapshot->downloads_last_week,
            'allTime' => $snapshot->downloaded,
            'live' => false,
        ];
    }

    /**
     * @return array{today: null, yesterday: null, lastWeek: null, allTime: null, live: bool}
     */
    protected function none(): array
    {
        return ['today' => null, 'yesterday' => null, 'lastWeek' => null, 'allTime' => null, 'live' => false];
    }
}
