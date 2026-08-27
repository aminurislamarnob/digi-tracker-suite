<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled telemetry maintenance
|--------------------------------------------------------------------------
|
| Driven by a single cPanel cron entry:
|
|   * * * * * cd /home/USER/digi-tracker && php artisan schedule:run
|
| Everything here is idempotent and recomputed from history, so a missed
| run costs nothing but a late number, and a re-run never double-counts.
|
*/

// Demote first, then roll up, so the rollup sees today's classification.
Schedule::command('telemetry:classify-sites')->dailyAt('02:00')->withoutOverlapping();

Schedule::command('telemetry:build-daily-stats')->dailyAt('02:15')->withoutOverlapping();

Schedule::command('telemetry:detect-anomalies')->hourly()->withoutOverlapping();
