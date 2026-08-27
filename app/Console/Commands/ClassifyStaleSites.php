<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;

/**
 * Demote sites that have gone quiet.
 *
 * "Active" is a claim with an expiry date: the last heartbeat said the
 * plugin was running, and the SDK promises another within a week. Four
 * missed beats and we stop asserting it.
 *
 * Crucially this never touches a deactivated site. Silence and "I am
 * leaving" are different signals -- one is probably a broken wp-cron, the
 * other is a decision -- and only a fresh heartbeat undoes the latter.
 */
class ClassifyStaleSites extends Command
{
    protected $signature = 'telemetry:classify-sites';

    protected $description = 'Demote sites that have stopped reporting to inactive';

    public function handle(): int
    {
        $cutoff = now()->subDays(Site::activeWindowDays());

        // Deliberately across accounts: this is maintenance, not a request.
        $demoted = CurrentAccount::withoutScope(fn () => Site::query()
            ->where('status', Site::STATUS_ACTIVE)
            ->where(fn ($query) => $query
                ->where('last_seen_at', '<', $cutoff)
                ->orWhereNull('last_seen_at'))
            ->update(['status' => Site::STATUS_INACTIVE]));

        $this->info("Demoted {$demoted} site(s) silent since {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
