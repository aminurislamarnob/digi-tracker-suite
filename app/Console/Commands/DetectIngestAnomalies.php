<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\RawPayload;
use App\Models\Site;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Watches ingest for traffic that has stopped looking like WordPress.
 *
 * Ingest has no authentication -- the protocol has none, and the hash that
 * routes a payload to an account is a plain body field visible in GPL
 * source. Throttling caps the rate; this catches the shape. Anyone can
 * inflate another project's install count, so the only honest posture is
 * to notice when someone does and to say plainly in the UI that these
 * counts are claimed, not proven.
 *
 * It alerts rather than blocks, on purpose: a genuine viral week and an
 * attack look identical from here, and silently discarding real installs
 * would be the worse failure.
 */
class DetectIngestAnomalies extends Command
{
    protected $signature = 'telemetry:detect-anomalies';

    protected $description = 'Log ingest traffic that does not look like weekly WordPress heartbeats';

    public function handle(): int
    {
        $since = now()->subHour();
        $found = 0;

        CurrentAccount::withoutScope(function () use ($since, &$found) {
            foreach (Project::query()->cursor() as $project) {
                $found += $this->inspect($project, $since);
            }

            $found += $this->inspectSources($since);
        });

        $this->info($found === 0
            ? 'No anomalies in the last hour.'
            : "Logged {$found} anomaly signal(s).");

        return self::SUCCESS;
    }

    protected function inspect(Project $project, $since): int
    {
        $found = 0;

        $payloads = RawPayload::query()
            ->where('project_id', $project->id)
            ->where('created_at', '>=', $since)
            ->count();

        if ($payloads > config('telemetry.anomaly.payloads_per_hour')) {
            $this->flag("Project {$project->slug}: {$payloads} payloads in the last hour.");
            $found++;
        }

        // The signal that matters most. A real project gains installs at the
        // pace wordpress.org serves downloads; a thousand brand-new sites in
        // an hour is somebody generating URLs.
        $newSites = Site::query()
            ->where('project_id', $project->id)
            ->where('first_seen_at', '>=', $since)
            ->count();

        if ($newSites > config('telemetry.anomaly.new_sites_per_hour')) {
            $this->flag("Project {$project->slug}: {$newSites} new sites in the last hour.");
            $found++;
        }

        return $found;
    }

    /**
     * Real heartbeats come from as many addresses as there are sites. A
     * single address speaking for hundreds is either a large host's shared
     * NAT or one machine pretending to be a crowd -- worth a human look.
     */
    protected function inspectSources($since): int
    {
        $noisy = RawPayload::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip')
            ->selectRaw('ip, COUNT(*) as hits')
            ->groupBy('ip')
            ->havingRaw('COUNT(*) > ?', [config('telemetry.anomaly.payloads_per_ip_per_hour')])
            ->get();

        foreach ($noisy as $row) {
            $this->flag("Source {$row->ip}: {$row->hits} payloads in the last hour.");
        }

        return $noisy->count();
    }

    protected function flag(string $message): void
    {
        Log::channel(config('logging.default'))->warning("[ingest anomaly] {$message}");

        $this->warn($message);
    }
}
