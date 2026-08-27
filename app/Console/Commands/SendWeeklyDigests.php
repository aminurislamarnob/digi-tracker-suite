<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigest;
use App\Models\DailyStat;
use App\Models\Deactivation;
use App\Models\EmailEvent;
use App\Models\EndUser;
use App\Models\Project;
use App\Services\Mailer;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * The week's numbers, to the authors who asked for them.
 *
 * Reads daily_stats like everything else. If the rollup has not run, the
 * digest does not go out: an email confidently reporting zero installs
 * because a cron missed is worse than no email.
 */
class SendWeeklyDigests extends Command
{
    protected $signature = 'telemetry:send-digests
                            {--project= : Send for one project slug only}
                            {--dry-run : Report who would receive one}';

    protected $description = 'Email each opted-in project a summary of the week';

    public function handle(Mailer $mailer): int
    {
        return CurrentAccount::withoutScope(function () use ($mailer) {
            $projects = Project::query()
                ->where('sends_weekly_digest', true)
                ->where('is_demo', false)
                ->when($this->option('project'), fn ($query, $slug) => $query->where('slug', $slug))
                ->with('account')
                ->get();

            if ($projects->isEmpty()) {
                $this->info('No project has the weekly digest switched on.');

                return self::SUCCESS;
            }

            $sent = 0;

            foreach ($projects as $project) {
                $sent += $this->digest($mailer, $project) ? 1 : 0;
            }

            $this->info($this->option('dry-run')
                ? "{$sent} digest(s) would be sent."
                : "{$sent} digest(s) sent.");

            return self::SUCCESS;
        });
    }

    protected function digest(Mailer $mailer, Project $project): bool
    {
        $summary = $this->summarise($project);

        if ($summary === null) {
            $this->line("  <fg=gray>{$project->slug}: no rollup yet, skipped</>");

            return false;
        }

        $recipients = $project->account->members()->pluck('email');

        if ($recipients->isEmpty()) {
            $this->line("  <fg=gray>{$project->slug}: account has no members</>");

            return false;
        }

        $this->line("  {$project->slug}: ".$recipients->count().' recipient(s)');

        if ($this->option('dry-run')) {
            return true;
        }

        foreach ($recipients as $email) {
            if ($mailer->isSuppressed($project->account_id, $email)) {
                continue;
            }

            Mail::to($email)->send(new WeeklyDigest($project, $summary));

            EmailEvent::acrossAccounts()->create([
                'account_id' => $project->account_id,
                'project_id' => $project->id,
                'type' => EmailEvent::WEEKLY_DIGEST,
                'recipient_index' => EndUser::indexFor($email),
                'sent_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null null when there is no rollup to report
     */
    protected function summarise(Project $project): ?array
    {
        $latest = DailyStat::query()
            ->where('project_id', $project->id)
            ->orderByDesc('date')
            ->first();

        if (! $latest) {
            return null;
        }

        $weekAgo = DailyStat::query()
            ->where('project_id', $project->id)
            ->whereDate('date', '<=', $latest->date->copy()->subWeek())
            ->orderByDesc('date')
            ->first();

        $week = DailyStat::query()
            ->where('project_id', $project->id)
            ->whereBetween('date', [$latest->date->copy()->subDays(6), $latest->date])
            ->get();

        $versions = $latest->by_version ?? [];
        arsort($versions);
        $topVersion = array_key_first($versions);

        return [
            'installs' => $latest->active_installs,
            // Null rather than zero when there is nothing to compare against:
            // "no change" and "no earlier data" are different statements.
            'installsDelta' => $weekAgo ? $latest->active_installs - $weekAgo->active_installs : null,
            'new' => $week->sum('new_installs'),
            'deactivations' => $week->sum('deactivations'),
            'reactivations' => $week->sum('reactivations'),
            'optInRate' => $week->sum('opted_in') + $week->sum('skipped') > 0
                ? round($week->sum('opted_in') / ($week->sum('opted_in') + $week->sum('skipped')) * 100, 1)
                : 0,
            'topVersion' => $topVersion,
            'topVersionShare' => $topVersion && array_sum($versions) > 0
                ? round($versions[$topVersion] / array_sum($versions) * 100)
                : 0,
            // The words people wrote are the most valuable thing in here, so
            // they go in the digest rather than only in the dashboard.
            'comments' => Deactivation::query()
                ->where('project_id', $project->id)
                ->whereNotNull('reason_info')
                ->where('created_at', '>=', now()->subWeek())
                ->latest()
                ->limit(5)
                ->get(),
            'url' => url('/admin/'.$project->account->slug.'/projects/'.$project->slug),
        ];
    }
}
