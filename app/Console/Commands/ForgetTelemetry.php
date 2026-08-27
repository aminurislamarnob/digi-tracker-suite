<?php

namespace App\Console\Commands;

use App\Models\Deactivation;
use App\Models\EndUser;
use App\Models\RawPayload;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteReport;
use App\Support\CurrentAccount;
use App\Support\SiteUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Erases everything this platform holds about one person or one site.
 *
 * The retention policy is "indefinite, delete on request", which is only a
 * policy if it can actually be carried out. Doing this by hand in tinker
 * reliably misses `raw_payloads` -- the original heartbeat, admin email
 * included -- because nothing cascades to it: it is deliberately kept
 * outside the foreign-key graph so a parser fix can be replayed.
 *
 * Deletes rather than anonymises. A hashed-out row still says a site
 * existed, on that date, running that plugin, and somebody asking to be
 * forgotten did not ask to leave a silhouette.
 */
class ForgetTelemetry extends Command
{
    protected $signature = 'telemetry:forget
                            {--email= : Erase an end user and every site they own}
                            {--site= : Erase one site by URL}
                            {--dry-run : Report what would go, delete nothing}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Erase all telemetry for an email address or a site (deletion on request)';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));
        $site = trim((string) $this->option('site'));

        if (($email === '') === ($site === '')) {
            $this->error('Give exactly one of --email or --site.');

            return self::INVALID;
        }

        // Deletion requests are not scoped to whoever is signed in, and this
        // runs from a console where nobody is.
        return CurrentAccount::withoutScope(
            fn () => $email !== '' ? $this->forgetEmail($email) : $this->forgetSite($site),
        );
    }

    protected function forgetEmail(string $email): int
    {
        $endUsers = EndUser::where('email_index', EndUser::indexFor($email))->get();

        if ($endUsers->isEmpty()) {
            $this->warn("Nothing held for {$email}.");

            // Not a failure: "we have nothing about you" is a valid answer to
            // a deletion request, and the caller should be able to say so.
            return self::SUCCESS;
        }

        $siteIds = Site::whereIn('end_user_id', $endUsers->pluck('id'))->pluck('id');

        return $this->erase(
            "everything held for {$email}",
            $siteIds,
            fn () => EndUser::whereIn('id', $endUsers->pluck('id'))->delete(),
            $email,
        );
    }

    protected function forgetSite(string $url): int
    {
        $key = SiteUrl::key($url);
        $sites = Site::where('site_key', $key)->get();

        if ($sites->isEmpty()) {
            $this->warn('Nothing held for '.SiteUrl::canonicalise($url).'.');

            return self::SUCCESS;
        }

        return $this->erase(
            'everything held for '.SiteUrl::canonicalise($url),
            $sites->pluck('id'),
            null,
            null,
            $sites->pluck('url')->all(),
        );
    }

    /**
     * @param  Collection<int, int>  $siteIds
     * @param  array<int, string>  $urls
     */
    protected function erase(
        string $what,
        $siteIds,
        ?callable $alsoDelete = null,
        ?string $email = null,
        array $urls = [],
    ): int {
        $payloads = $this->payloadQuery($siteIds, $email, $urls);

        /*
         * End users do not cascade from sites, and they hold the contact
         * details. Erasing a site by URL while leaving its owner behind
         * keeps an email address attached to nothing -- a deletion that
         * looks complete and is not.
         *
         * Only those left with no sites at all: somebody who runs the
         * plugin on three sites and asks for one to be removed has not
         * asked to be forgotten.
         */
        $ownerIds = $this->strandedOwners($siteIds);

        // The email path targets an end user directly, and may match someone
        // who owns no sites at all -- an orphan left behind by an earlier
        // deletion. Counting only stranded owners would report zero while
        // still deleting them, which is the worst way to be right.
        if ($email !== null) {
            $ownerIds = $ownerIds
                ->merge(EndUser::where('email_index', EndUser::indexFor($email))->pluck('id'))
                ->unique()
                ->values();
        }

        $counts = [
            'sites' => $siteIds->count(),
            'reports' => SiteReport::whereIn('site_id', $siteIds)->count(),
            'plugins' => SitePlugin::whereIn('site_id', $siteIds)->count(),
            'deactivations' => Deactivation::whereIn('site_id', $siteIds)->count(),
            'end users' => $ownerIds->count(),
            'raw payloads' => $payloads->count(),
        ];

        $this->newLine();
        $this->line("Erasing <options=bold>{$what}</>:");
        $this->newLine();

        // twoColumnDetail, not a padded line(): SymfonyStyle normalises
        // runs of whitespace, so hand-aligned columns collapse into prose.
        foreach ($counts as $label => $count) {
            $this->components->twoColumnDetail($label, number_format($count));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('This cannot be undone. Proceed?')) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($siteIds, $alsoDelete, $email, $urls, $ownerIds) {
            // Reports, plugins and deactivations cascade from sites; raw
            // payloads and end users do not, which is why this is a command
            // and not a snippet somebody runs under pressure.
            $this->payloadQuery($siteIds, $email, $urls)->delete();

            Site::whereIn('id', $siteIds)->delete();

            EndUser::whereIn('id', $ownerIds)->delete();

            $alsoDelete && $alsoDelete();
        });

        $this->info('Erased.');

        return self::SUCCESS;
    }

    /**
     * Owners who will have no sites left once these are gone.
     *
     * @param  Collection<int, int>  $siteIds
     * @return Collection<int, int>
     */
    protected function strandedOwners($siteIds): Collection
    {
        $ownerIds = Site::whereIn('id', $siteIds)
            ->whereNotNull('end_user_id')
            ->distinct()
            ->pluck('end_user_id');

        if ($ownerIds->isEmpty()) {
            return $ownerIds;
        }

        $keeping = Site::whereIn('end_user_id', $ownerIds)
            ->whereNotIn('id', $siteIds)
            ->distinct()
            ->pluck('end_user_id');

        return $ownerIds->diff($keeping)->values();
    }

    /**
     * Raw payloads are matched on the address or URL inside the stored JSON,
     * not through a relationship. There is no foreign key to follow: the row
     * is kept deliberately outside the graph so a parser change can be
     * replayed against history, which means deletion has to find it by hand.
     *
     * @param  Collection<int, int>  $siteIds
     * @param  array<int, string>  $urls
     */
    protected function payloadQuery($siteIds, ?string $email, array $urls)
    {
        $query = RawPayload::query()->whereRaw('0 = 1');

        if ($email !== null) {
            $query->orWhereJsonContains('payload->admin_email', $email);
        }

        foreach ($urls as $url) {
            $query->orWhereJsonContains('payload->url', $url);
        }

        // Also catch payloads whose URL canonicalises onto a doomed site but
        // was written differently on the wire -- http vs https, www or not.
        if ($siteIds->isNotEmpty()) {
            foreach (Site::whereIn('id', $siteIds)->pluck('url') as $url) {
                $query->orWhereJsonContains('payload->url', $url);
            }
        }

        return $query;
    }
}
