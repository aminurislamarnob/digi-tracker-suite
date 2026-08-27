<?php

namespace App\Services;

use App\Models\Project;
use App\Models\RepoRelease;
use App\Models\RepoSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the repository's public record into rows we own.
 *
 * The reason this exists rather than the dashboard querying wordpress.org
 * directly is the same reason daily_stats exists: a chart must never depend
 * on a third party answering. It also means the history accumulates. Most
 * of these fields have no public history at all -- active_installs, ratings,
 * support threads are only ever "as of now" -- so a day we do not capture
 * is a day nobody can recover later.
 */
class RepoSnapshotter
{
    public function __construct(protected WordPressOrg $repository) {}

    /**
     * Capture everything public about one project.
     *
     * @return array{snapshot: RepoSnapshot|null, downloads: int, releases: int}
     */
    public function capture(Project $project, ?CarbonImmutable $on = null): array
    {
        $slug = $project->wporg_slug;

        if (blank($slug)) {
            return ['snapshot' => null, 'downloads' => 0, 'releases' => 0];
        }

        $on ??= CarbonImmutable::parse(Carbon::now()->toDateString());

        return [
            'snapshot' => $this->snapshot($project, $slug, $on),
            'downloads' => $this->downloads($project, $slug),
            'releases' => $this->releases($project, $slug),
        ];
    }

    protected function snapshot(Project $project, string $slug, CarbonImmutable $on): ?RepoSnapshot
    {
        $info = $this->repository->plugin($slug);

        if ($info === null) {
            return null;
        }

        $snapshot = RepoSnapshot::acrossAccounts()->updateOrCreate(
            ['project_id' => $project->id, 'captured_on' => $on->toDateString()],
            [
                'account_id' => $project->account_id,
                'active_installs' => $this->intOrNull($info['active_installs'] ?? null),
                'downloaded' => $this->intOrNull($info['downloaded'] ?? null),
                'rating' => $this->intOrNull($info['rating'] ?? null),
                'num_ratings' => $this->intOrNull($info['num_ratings'] ?? null),
                'ratings' => is_array($info['ratings'] ?? null) ? $info['ratings'] : null,
                'support_threads' => $this->intOrNull($info['support_threads'] ?? null),
                'support_threads_resolved' => $this->intOrNull($info['support_threads_resolved'] ?? null),
                'version' => $info['version'] ?? null,
                'requires' => $this->stringOrNull($info['requires'] ?? null),
                'requires_php' => $this->stringOrNull($info['requires_php'] ?? null),
                'tested' => $this->stringOrNull($info['tested'] ?? null),
                'last_updated_at' => $this->parseDate($info['last_updated'] ?? null),
                'version_distribution' => $this->repository->versionDistribution($slug) ?: null,
            ],
        );

        /*
         * A version we have never seen before, with no Subversion date to
         * go on, is still worth recording -- but only ever as "no later
         * than today". releases() will overwrite it with the exact tag date
         * the moment Subversion offers one.
         */
        if (filled($snapshot->version)) {
            RepoRelease::acrossAccounts()->firstOrCreate(
                ['project_id' => $project->id, 'version' => $snapshot->version],
                [
                    'account_id' => $project->account_id,
                    'released_on' => $on->toDateString(),
                    'source' => RepoRelease::FROM_OBSERVATION,
                ],
            );
        }

        return $snapshot;
    }

    /**
     * @return int rows written or corrected
     */
    protected function downloads(Project $project, string $slug): int
    {
        $counts = $this->repository->dailyDownloads($slug);

        if ($counts === []) {
            return 0;
        }

        $rows = [];
        $now = Carbon::now();

        foreach ($counts as $date => $downloads) {
            $rows[] = [
                'account_id' => $project->account_id,
                'project_id' => $project->id,
                'date' => $date,
                'downloads' => $downloads,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        /*
         * Upsert rather than insert: the repository revises recent days as
         * mirrors report in, so the tail of this series is provisional for
         * a while. Chunked because the first call for a project backfills
         * two years in one go.
         */
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('repo_downloads')->upsert($chunk, ['project_id', 'date'], ['downloads', 'updated_at']);
        }

        return count($rows);
    }

    /**
     * @return int releases whose date is now known exactly
     */
    protected function releases(Project $project, string $slug): int
    {
        $dates = $this->repository->releaseDates($slug);

        if ($dates === []) {
            return 0;
        }

        $written = 0;

        foreach ($dates as $version => $releasedOn) {
            $existing = RepoRelease::acrossAccounts()
                ->where('project_id', $project->id)
                ->where('version', $version)
                ->first();

            /*
             * An exact date always wins over an observed one, but two exact
             * dates for the same tag never disagree, so there is nothing to
             * gain from rewriting a row that is already authoritative.
             */
            if ($existing?->isExact()) {
                continue;
            }

            RepoRelease::acrossAccounts()->updateOrCreate(
                ['project_id' => $project->id, 'version' => $version],
                [
                    'account_id' => $project->account_id,
                    'released_on' => $releasedOn->toDateString(),
                    'source' => RepoRelease::FROM_SVN,
                ],
            );

            $written++;
        }

        return $written;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** The API returns '' for unset fields, which is not the same as absent. */
    protected function stringOrNull(mixed $value): ?string
    {
        return filled($value) && is_scalar($value) ? (string) $value : null;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // '2026-05-15 10:36am GMT' -- parseable, but not by a strict format.
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
