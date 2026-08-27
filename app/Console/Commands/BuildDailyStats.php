<?php

namespace App\Console\Commands;

use App\Services\DailyStatsBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildDailyStats extends Command
{
    /**
     * --days lets a range be rebuilt after a bug fix. The builder
     * recomputes from history rather than incrementing, so re-running a
     * date is always safe and always lands on the same numbers.
     */
    protected $signature = 'telemetry:build-daily-stats
                            {--date= : The date to build, defaults to yesterday}
                            {--days=1 : Number of days to build, working backwards}';

    protected $description = 'Roll telemetry history up into the daily_stats table charts read';

    public function handle(DailyStatsBuilder $builder): int
    {
        // Yesterday by default: today is still accumulating heartbeats, and
        // a half-finished day on a chart reads as a collapse in installs.
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        $days = max(1, (int) $this->option('days'));

        for ($i = 0; $i < $days; $i++) {
            $day = $date->copy()->subDays($i);
            $projects = $builder->buildAll($day);

            $this->info("{$day->toDateString()}: rolled up {$projects} project(s).");
        }

        return self::SUCCESS;
    }
}
