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
 * The public half of the picture, from wordpress.org.
 *
 * Most of what this captures has no public history -- active installs,
 * ratings and support threads are only ever "as of now" -- so a day missed
 * is a day nobody can recover later. That is the whole argument for a daily
 * run even when nothing appears to have changed.
 *
 * 03:00 rather than alongside the rollup: it is the only scheduled task
 * that depends on a third party answering, and it has no business competing
 * for the same minute as work that must not be late.
 */
Schedule::command('telemetry:fetch-repo-stats')->dailyAt('03:00')->withoutOverlapping();

/*
 * Search rankings, an hour later and deliberately apart.
 *
 * Each keyword costs several requests to a public API we are a guest on.
 * Running it back-to-back with the snapshot would concentrate every call we
 * make into one window, and being rate-limited off wordpress.org would take
 * the snapshot down with it.
 */
Schedule::command('telemetry:track-keywords')->dailyAt('04:00')->withoutOverlapping();

/*
 * After the rollup, not before: the digest reads daily_stats, and a digest
 * confidently reporting last night's numbers because it ran first is worse
 * than one that arrives an hour later.
 *
 * Monday morning, because a weekly summary read on a Friday afternoon is a
 * weekly summary nobody acts on.
 */
Schedule::command('telemetry:send-digests')->weeklyOn(1, '08:00')->withoutOverlapping();
