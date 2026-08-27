<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\RepoSnapshotter;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;

/**
 * The daily capture of everything wordpress.org says about our plugins.
 *
 * Most of what this collects has no public history -- active installs,
 * ratings, support threads are only ever "as of now" -- so a day missed is
 * a day that cannot be recovered afterwards. That is the argument for
 * running it every day even when nothing looks like it changed.
 *
 * It is also why one project failing must not stop the rest: a single
 * unreachable slug should cost one project one day, not every project.
 */
class FetchRepoStats extends Command
{
    protected $signature = 'telemetry:fetch-repo-stats
                            {--project= : Limit to one project slug}
                            {--dry-run : Report what would be fetched}';

    protected $description = 'Capture public wordpress.org stats for every linked project';

    public function handle(RepoSnapshotter $snapshotter): int
    {
        return CurrentAccount::withoutScope(function () use ($snapshotter) {
            $projects = Project::query()
                ->whereNotNull('wporg_slug')
                ->where('is_demo', false)
                ->when($this->option('project'), fn ($query, $slug) => $query->where('slug', $slug))
                ->orderBy('id')
                ->get();

            if ($projects->isEmpty()) {
                $this->info('No project is linked to a wordpress.org slug.');

                return self::SUCCESS;
            }

            $captured = 0;

            foreach ($projects as $project) {
                if ($this->option('dry-run')) {
                    $this->line("  <fg=gray>{$project->slug} -> {$project->wporg_slug}</>");
                    $captured++;

                    continue;
                }

                try {
                    $result = $snapshotter->capture($project);
                } catch (\Throwable $e) {
                    // One bad slug costs one project one day, not the run.
                    $this->error("  {$project->slug}: {$e->getMessage()}");
                    report($e);

                    continue;
                }

                if (! $result['snapshot']) {
                    $this->line("  <fg=yellow>{$project->slug}: no public record for '{$project->wporg_slug}'</>");

                    continue;
                }

                $captured++;

                $this->components->twoColumnDetail(
                    "<fg=green>{$project->slug}</>",
                    sprintf(
                        '%s installs, v%s, %d download days, %d release dates',
                        number_format((int) $result['snapshot']->active_installs),
                        $result['snapshot']->version ?? '?',
                        $result['downloads'],
                        $result['releases'],
                    ),
                );
            }

            $this->newLine();
            $this->info($this->option('dry-run')
                ? "{$captured} project(s) would be captured."
                : "{$captured} project(s) captured.");

            return self::SUCCESS;
        });
    }
}
