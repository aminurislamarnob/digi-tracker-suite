<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\RepoDownloads;
use App\Services\RepoSnapshotter;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * One project's public record, fetched now rather than at 03:00.
 *
 * Queued, and not merely for tidiness. A capture is four calls to
 * wordpress.org -- plugin_information, the summary, the 730-day download
 * series, and a Subversion PROPFIND -- each of which may spend twenty
 * seconds timing out and be retried twice before it gives up. That is a
 * minute of upstream latency per call in the worst case, and none of it
 * belongs in a page render with a person watching a spinner.
 *
 * It writes exactly what the nightly command writes, through the same
 * snapshotter, so a manual refresh and a scheduled one are the same
 * operation -- updateOrCreate on today's row, upsert on the download
 * series. Pressing the button twice costs a duplicate fetch, never a
 * duplicate row.
 */
class RefreshRepoStats implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, deliberately.
     *
     * WordPressOrg already retries each request twice internally and then
     * fails soft, so a job that reaches its end has not thrown -- it has
     * recorded whatever it could reach. Retrying at this level would only
     * re-fetch a repository that is already struggling, on behalf of a
     * button press nobody is waiting on any more.
     */
    public int $tries = 1;

    /** Four endpoints, each able to spend a minute failing. */
    public int $timeout = 300;

    /**
     * How long a project is left alone after a manual refresh.
     *
     * Matches RepoDownloads' cache window, which is the interval over which
     * the page would show the same numbers anyway: refreshing more often
     * than that changes nothing on screen and spends someone else's rate
     * limit to do it.
     */
    public const COOLDOWN_SECONDS = RepoDownloads::CACHE_SECONDS;

    public function __construct(public int $projectId) {}

    /** The key that both throttles the button and reports the wait. */
    public static function cooldownKey(int $projectId): string
    {
        return "wporg:refresh:{$projectId}";
    }

    public function handle(RepoSnapshotter $snapshotter): void
    {
        CurrentAccount::withoutScope(function () use ($snapshotter) {
            $project = Project::query()->find($this->projectId);

            /*
             * Re-checked here rather than trusted from dispatch. The slug
             * can be cleared, or the project deleted, between the press and
             * a worker picking this up.
             */
            if ($project === null || $project->is_demo || ! $project->isOnRepository()) {
                return;
            }

            $snapshotter->capture($project);

            /*
             * The headline reads its four download figures live through a
             * fifteen-minute cache. Leaving that in place would mean a
             * refresh that visibly updated the snapshot while the largest
             * numbers on the page sat unchanged -- which reads as the
             * button not having worked.
             */
            Cache::forget(RepoDownloads::cacheKey($project->wporg_slug));
        });
    }
}
