<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled telemetry maintenance
|--------------------------------------------------------------------------
|
| The scheduler is not an alternative to cron -- it is a dispatcher cron
| fires. What it buys us is that the crontab stays one line for the life of
| the project, and every decision about *when* something runs lives here,
| in version control, testable with `php artisan schedule:list`.
|
| The whole crontab, on cPanel:
|
|   * * * * * cd /home/USER/digi-tracker && php artisan schedule:run >> /dev/null 2>&1
|
| Everything below is idempotent and recomputed from history, so a missed
| run costs nothing but a late number, and a re-run never double-counts.
|
*/

/*
 * The queue worker, scheduled rather than supervised.
 *
 * cPanel kills long-running processes, so there is no daemon to keep
 * alive. Instead a fresh worker starts each minute, drains the queue and
 * exits before the next one begins -- 55 seconds, not 60, so two workers
 * never overlap at the boundary.
 *
 * runInBackground() matters: without it schedule:run would block for the
 * full 55 seconds and any task due in the same minute would be late.
 */
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping();

// Demote first, then roll up, so the rollup sees today's classification.
Schedule::command('telemetry:classify-sites')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('telemetry:build-daily-stats')->dailyAt('02:15')->withoutOverlapping();

Schedule::command('telemetry:detect-anomalies')->hourly()->withoutOverlapping();

/*
 * After the rollup, not before: the digest reads daily_stats, and a digest
 * confidently reporting last night's numbers because it ran first is worse
 * than one that arrives an hour later.
 *
 * Monday morning, because a weekly summary read on a Friday afternoon is a
 * weekly summary nobody acts on.
 */
Schedule::command('telemetry:send-digests')->weeklyOn(1, '08:00')->withoutOverlapping();
