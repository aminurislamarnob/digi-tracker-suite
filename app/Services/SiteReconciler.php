<?php

namespace App\Services;

use App\Contracts\GeoLocator;
use App\Jobs\SendDeactivationEmails;
use App\Models\Deactivation;
use App\Models\EndUser;
use App\Models\ProjectMetaField;
use App\Models\RawPayload;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteReport;
use App\Support\SiteUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Turns a raw heartbeat into current state plus a history row.
 *
 * Everything that interprets the payload lives here, so the ingest
 * controllers stay a thin durable write and a parser change can be
 * replayed against stored payloads.
 *
 * Both /track and /deactivate arrive here, because a deactivation carries
 * the full insights payload as well as the reason -- the only difference
 * is what it does to the site's status at the end.
 */
class SiteReconciler
{
    /**
     * An abusive or broken payload could claim thousands of plugins. The
     * ceiling is far above any real WordPress install.
     */
    protected const MAX_PLUGINS = 500;

    public function __construct(protected GeoLocator $geo) {}

    public function reconcile(RawPayload $payload): Site
    {
        $data = $payload->payload;
        $isDeactivation = $payload->route === RawPayload::ROUTE_DEACTIVATE;

        return DB::transaction(function () use ($payload, $data, $isDeactivation) {
            $endUser = $this->upsertEndUser($payload, $data);
            $site = $this->upsertSite($payload, $data, $endUser, $isDeactivation);

            // A deactivation is always worth a report: it is a distinct event,
            // and it is the last environment snapshot we will ever get.
            if ($isDeactivation || ! $this->isDuplicate($site, $data)) {
                $this->recordReport($payload, $site, $data);
            }

            $this->syncPlugins($payload, $site, $data);

            if ($isDeactivation) {
                $this->recordDeactivation($payload, $site, $data);
            }

            return $site;
        });
    }

    protected function upsertEndUser(RawPayload $payload, array $data): ?EndUser
    {
        $email = trim((string) Arr::get($data, 'admin_email'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $index = EndUser::indexFor($email);

        $endUser = EndUser::acrossAccounts()
            ->where('project_id', $payload->project_id)
            ->where('email_index', $index)
            ->first();

        if (! $endUser) {
            return EndUser::create([
                'account_id' => $payload->account_id,
                'project_id' => $payload->project_id,
                'email' => $email,
                'email_index' => $index,
                'first_name' => Arr::get($data, 'first_name') ?: null,
                'last_name' => Arr::get($data, 'last_name') ?: null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $endUser->update([
            'first_name' => Arr::get($data, 'first_name') ?: $endUser->first_name,
            'last_name' => Arr::get($data, 'last_name') ?: $endUser->last_name,
            'last_seen_at' => now(),
        ]);

        return $endUser;
    }

    protected function upsertSite(RawPayload $payload, array $data, ?EndUser $endUser, bool $isDeactivation): Site
    {
        $url = (string) Arr::get($data, 'url', '');
        $canonical = SiteUrl::canonicalise($url);
        $key = SiteUrl::key($url);

        $site = Site::acrossAccounts()
            ->where('project_id', $payload->project_id)
            ->where('site_key', $key)
            ->first();

        // The payload's own ip_address comes from an icanhazip lookup the
        // customer's server makes -- a third-party call we strip in our SDK
        // fork. Prefer what we observed; fall back for Appsero clients.
        $ip = $payload->ip ?: (Arr::get($data, 'ip_address') ?: null);

        $attributes = [
            'end_user_id' => $endUser?->id,
            'url' => $url,
            'canonical_url' => $canonical,
            'ua_fingerprint' => $this->fingerprint($payload->user_agent),
            'name' => Arr::get($data, 'site') ?: null,
            'ip' => $ip,
            'is_local' => filter_var(Arr::get($data, 'is_local'), FILTER_VALIDATE_BOOL),
            'current_version' => Arr::get($data, 'project_version') ?: null,
            'wp_version' => Arr::get($data, 'wp.version') ?: null,
            'php_version' => Arr::get($data, 'server.php_version') ?: null,
            'last_seen_at' => now(),
        ];

        // Only pay for the lookup when the answer could have changed.
        if ($ip && (! $site || $site->ip !== $ip || ! $site->country)) {
            $attributes['country'] = $this->geo->country($ip) ?? $site?->country;
        }

        /*
         * A partial payload must not erase what we already know.
         *
         * Not every route carries the full insights bundle -- a hand-rolled
         * integration, an older client, or a deactivation posted from a
         * half-loaded admin page can arrive with the environment missing.
         * Writing null over a known PHP version would quietly empty the
         * column that answers "can we drop 7.4?".
         */
        if ($site) {
            foreach (['name', 'ip', 'ua_fingerprint', 'current_version', 'wp_version', 'php_version', 'country'] as $field) {
                $attributes[$field] = ($attributes[$field] ?? null) ?: $site->{$field};
            }
        }

        if (! $site) {
            return Site::create($attributes + [
                'account_id' => $payload->account_id,
                'project_id' => $payload->project_id,
                'site_key' => $key,
                'status' => $isDeactivation ? Site::STATUS_DEACTIVATED : Site::STATUS_ACTIVE,
                'first_seen_at' => now(),
            ]);
        }

        // A heartbeat after a deactivation is a reactivation, not a new
        // install. A deactivation payload obviously gets no such promotion --
        // recordDeactivation sets its status once the event is logged.
        if (! $isDeactivation && $site->status !== Site::STATUS_ACTIVE) {
            if ($site->status === Site::STATUS_DEACTIVATED) {
                $this->closeOpenDeactivation($site);
            }

            $attributes['status'] = Site::STATUS_ACTIVE;
            $attributes['deactivated_at'] = null;
        }

        $site->update($attributes);

        return $site;
    }

    /**
     * Close the churn event this heartbeat has just ended.
     *
     * The stamp lands on the deactivation row rather than on `sites`
     * because the nightly rollup has to answer "how many came back on the
     * 3rd?" long after the site has moved on -- and only a site that told
     * us it was leaving can come back. A site that merely went quiet was
     * never a churn event and does not become a reactivation.
     */
    protected function closeOpenDeactivation(Site $site): void
    {
        Deactivation::acrossAccounts()
            ->where('site_id', $site->id)
            ->whereNull('reactivated_at')
            ->latest('created_at')
            ->limit(1)
            ->update(['reactivated_at' => now()]);
    }

    /**
     * A real site reports once a week. Anything arriving again within a few
     * hours with an identical environment is a retry or a misconfigured
     * cron: worth recording as "seen", not worth a second history row that
     * would double-count the site in every distribution.
     */
    protected function isDuplicate(Site $site, array $data): bool
    {
        if ($site->wasRecentlyCreated) {
            return false;
        }

        $window = (int) config('telemetry.duplicate_window_hours', 6);

        if ($window <= 0) {
            return false;
        }

        $last = SiteReport::acrossAccounts()
            ->where('site_id', $site->id)
            ->latest('reported_at')
            ->first();

        if (! $last || $last->reported_at->lt(now()->subHours($window))) {
            return false;
        }

        return $last->project_version === (Arr::get($data, 'project_version') ?: null)
            && $last->wp_version === (Arr::get($data, 'wp.version') ?: null)
            && $last->php_version === (Arr::get($data, 'server.php_version') ?: null);
    }

    protected function recordReport(RawPayload $payload, Site $site, array $data): SiteReport
    {
        return SiteReport::create([
            'account_id' => $payload->account_id,
            'project_id' => $payload->project_id,
            'site_id' => $site->id,
            'project_version' => Arr::get($data, 'project_version') ?: null,

            'wp_version' => Arr::get($data, 'wp.version') ?: null,
            'php_version' => Arr::get($data, 'server.php_version') ?: null,
            'mysql_version' => Arr::get($data, 'server.mysql_version') ?: null,
            'server_software' => Arr::get($data, 'server.software') ?: null,
            'locale' => Arr::get($data, 'wp.locale') ?: null,
            'multisite' => $this->wpYesNo(Arr::get($data, 'wp.multisite')),
            'memory_limit' => Arr::get($data, 'wp.memory_limit') ?: null,
            'debug_mode' => $this->wpYesNo(Arr::get($data, 'wp.debug_mode')),

            'theme_slug' => Arr::get($data, 'wp.theme_slug') ?: null,
            'theme_name' => Arr::get($data, 'wp.theme_name') ?: null,
            'theme_version' => Arr::get($data, 'wp.theme_version') ?: null,

            'users_total' => (int) Arr::get($data, 'users.total', 0) ?: null,
            'users_by_role' => $this->roleCounts($data),
            'active_plugins' => (int) Arr::get($data, 'active_plugins', 0) ?: null,
            'inactive_plugins' => (int) Arr::get($data, 'inactive_plugins', 0) ?: null,

            'extra' => $this->whitelistedExtra($payload, $data),

            'client_version' => Arr::get($data, 'client') ?: null,
            'reported_at' => now(),
        ]);
    }

    /**
     * add_extra() lets an integrator send whatever they like, so without a
     * whitelist this is an unbounded, untyped funnel into our database --
     * and the likeliest route by which personal data we never asked for
     * would arrive. Unregistered keys are dropped, silently and on purpose:
     * the site never reads our response, so there is nobody to tell.
     */
    protected function whitelistedExtra(RawPayload $payload, array $data): ?array
    {
        $extra = Arr::get($data, 'extra');

        if (! is_array($extra) || $extra === []) {
            return null;
        }

        $fields = ProjectMetaField::acrossAccounts()
            ->where('project_id', $payload->project_id)
            ->get()
            ->keyBy('key');

        if ($fields->isEmpty()) {
            return null;
        }

        $kept = [];

        foreach ($extra as $key => $value) {
            if ($field = $fields->get($key)) {
                $kept[$key] = $field->cast($value);
            }
        }

        return $kept ?: null;
    }

    /**
     * The payload carries active plugins only, keyed by slug, with our own
     * plugin already removed by the SDK. Current state is all we keep --
     * see the site_plugins migration for why there is no history here.
     */
    protected function syncPlugins(RawPayload $payload, Site $site, array $data): void
    {
        $plugins = Arr::get($data, 'plugins');

        if (! is_array($plugins)) {
            return;
        }

        $plugins = array_slice($plugins, 0, self::MAX_PLUGINS, true);
        $seen = [];

        foreach ($plugins as $slug => $plugin) {
            $slug = mb_substr((string) $slug, 0, 191);

            if ($slug === '' || ! is_array($plugin)) {
                continue;
            }

            $seen[] = $slug;

            SitePlugin::acrossAccounts()->updateOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                [
                    'account_id' => $payload->account_id,
                    'project_id' => $payload->project_id,
                    'name' => Arr::get($plugin, 'name') ?: null,
                    'version' => mb_substr((string) Arr::get($plugin, 'version'), 0, 32) ?: null,
                    'last_seen_at' => now(),
                ],
            );
        }

        // Anything absent from this payload is no longer active on the site.
        SitePlugin::acrossAccounts()
            ->where('site_id', $site->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('slug', $seen))
            ->delete();
    }

    /**
     * Append-only. A site that leaves twice has churned twice, and both
     * events are real -- the reason text is the only feedback in the user's
     * own words this platform will ever receive.
     */
    protected function recordDeactivation(RawPayload $payload, Site $site, array $data): Deactivation
    {
        $deactivation = Deactivation::create([
            'account_id' => $payload->account_id,
            'project_id' => $payload->project_id,
            'site_id' => $site->id,
            'reason_id' => mb_substr((string) Arr::get($data, 'reason_id'), 0, 64) ?: null,
            'reason_info' => trim((string) Arr::get($data, 'reason_info')) ?: null,
            'project_version' => Arr::get($data, 'project_version') ?: null,
            'theme_slug' => Arr::get($data, 'wp.theme_slug') ?: null,
            'theme_name' => Arr::get($data, 'wp.theme_name') ?: null,
            'theme_version' => Arr::get($data, 'wp.theme_version') ?: null,
        ]);

        $site->update([
            'status' => Site::STATUS_DEACTIVATED,
            'deactivated_at' => now(),
        ]);

        /*
         * Queued after the transaction commits, not inside it. Dispatching
         * within would hand the job a row the worker cannot see yet if it
         * picks it up before the commit lands -- and a mail failure must
         * never roll back the churn event that caused it.
         */
        DB::afterCommit(fn () => SendDeactivationEmails::dispatch($deactivation->id));

        return $deactivation;
    }

    /**
     * Roles are open-ended -- any plugin can register one -- so they are
     * stored as JSON rather than columns. Form encoding makes every count
     * a string, so cast them or the charts end up summing "12" as text.
     */
    protected function roleCounts(array $data): ?array
    {
        $roles = Arr::except((array) Arr::get($data, 'users', []), 'total');

        $counts = array_map(fn ($count) => (int) $count, $roles);

        return $counts ?: null;
    }

    /**
     * The SDK reports wp[] flags as the literal strings 'Yes' and 'No',
     * not as booleans -- see Insights::get_wp_info().
     */
    protected function wpYesNo(mixed $value): bool
    {
        return is_string($value)
            ? strtolower($value) === 'yes'
            : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    protected function fingerprint(?string $userAgent): ?string
    {
        // Appsero/<md5(home_url)>; -- a stable per-site marker that survives
        // URL normalisation quirks, useful for spotting a site whose URL moved.
        if ($userAgent && preg_match('#/([a-f0-9]{32});#i', $userAgent, $m)) {
            return $m[1];
        }

        return null;
    }
}
