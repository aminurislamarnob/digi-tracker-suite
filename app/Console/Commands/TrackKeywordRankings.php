<?php

namespace App\Console\Commands;

use App\Models\RepoKeyword;
use App\Models\RepoRanking;
use App\Services\WordPressOrg;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Where each tracked keyword puts us in repository search today.
 *
 * Each keyword costs up to `RANK_DEPTH / 100` requests against a public API
 * we are a guest on, so the terms are chosen by hand and the loop pauses
 * between them. Being rate-limited off wordpress.org would take the daily
 * snapshot down with it, which is a far worse outcome than a slow run.
 */
class TrackKeywordRankings extends Command
{
    protected $signature = 'telemetry:track-keywords
                            {--project= : Limit to one project slug}
                            {--dry-run : List the keywords without querying}';

    protected $description = 'Record repository search position for each tracked keyword';

    /** Pause between keywords, in microseconds. */
    protected const COURTESY_DELAY = 500_000;

    public function handle(WordPressOrg $repository): int
    {
        return CurrentAccount::withoutScope(function () use ($repository) {
            $keywords = RepoKeyword::acrossAccounts()
                ->where('is_active', true)
                ->whereHas('project', function ($query) {
                    $query->whereNotNull('wporg_slug')->where('is_demo', false);

                    if ($slug = $this->option('project')) {
                        $query->where('slug', $slug);
                    }
                })
                ->with('project')
                ->orderBy('project_id')
                ->get();

            if ($keywords->isEmpty()) {
                $this->info('No active keywords to track.');

                return self::SUCCESS;
            }

            $today = CarbonImmutable::parse(Carbon::now()->toDateString());
            $checked = 0;

            foreach ($keywords as $keyword) {
                $project = $keyword->project;

                if ($this->option('dry-run')) {
                    $this->line("  <fg=gray>{$project->slug}: \"{$keyword->keyword}\"</>");
                    $checked++;

                    continue;
                }

                try {
                    $result = $repository->searchPosition($project->wporg_slug, $keyword->keyword);
                } catch (\Throwable $e) {
                    $this->error("  \"{$keyword->keyword}\": {$e->getMessage()}");
                    report($e);

                    continue;
                }

                RepoRanking::acrossAccounts()->updateOrCreate(
                    [
                        'repo_keyword_id' => $keyword->id,
                        'captured_on' => $today->toDateString(),
                    ],
                    [
                        'account_id' => $keyword->account_id,
                        'project_id' => $project->id,
                        'position' => $result['position'],
                        'searched_depth' => $result['depth'],
                        'total_results' => $result['total'],
                    ],
                );

                $checked++;

                $this->components->twoColumnDetail(
                    "{$project->slug}: \"{$keyword->keyword}\"",
                    $result['position'] === null
                        // Never rendered as a number: "outside the window"
                        // is the absence of a rank, not a bad one.
                        ? "<fg=yellow>not in top {$result['depth']}</>"
                        : "<fg=green>#{$result['position']}</> of ".number_format((int) $result['total']),
                );

                usleep(self::COURTESY_DELAY);
            }

            $this->newLine();
            $this->info($this->option('dry-run')
                ? "{$checked} keyword(s) would be checked."
                : "{$checked} keyword(s) checked.");

            return self::SUCCESS;
        });
    }
}
