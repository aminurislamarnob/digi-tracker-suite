<?php

namespace App\Services;

use App\Models\EndUser;
use App\Models\RawPayload;
use App\Models\Site;
use App\Models\SiteReport;
use App\Support\SiteUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Turns a raw heartbeat into current state plus a history row.
 *
 * Everything that interprets the payload lives here, so the ingest
 * controller stays a thin durable write and a parser change can be
 * replayed against stored payloads.
 */
class SiteReconciler
{
    public function reconcile(RawPayload $payload): Site
    {
        $data = $payload->payload;

        return DB::transaction(function () use ($payload, $data) {
            $endUser = $this->upsertEndUser($payload, $data);
            $site = $this->upsertSite($payload, $data, $endUser);

            $this->recordReport($payload, $site, $data);

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

    protected function upsertSite(RawPayload $payload, array $data, ?EndUser $endUser): Site
    {
        $url = (string) Arr::get($data, 'url', '');
        $canonical = SiteUrl::canonicalise($url);
        $key = SiteUrl::key($url);

        $site = Site::acrossAccounts()
            ->where('project_id', $payload->project_id)
            ->where('site_key', $key)
            ->first();

        $attributes = [
            'end_user_id' => $endUser?->id,
            'url' => $url,
            'canonical_url' => $canonical,
            'ua_fingerprint' => $this->fingerprint($payload->user_agent),
            'name' => Arr::get($data, 'site') ?: null,

            // The payload's own ip_address comes from an icanhazip lookup the
            // customer's server makes -- a third-party call we strip in our
            // SDK fork. Prefer what we observed; fall back for Appsero clients.
            'ip' => $payload->ip ?: (Arr::get($data, 'ip_address') ?: null),

            'is_local' => filter_var(Arr::get($data, 'is_local'), FILTER_VALIDATE_BOOL),
            'current_version' => Arr::get($data, 'project_version') ?: null,
            'wp_version' => Arr::get($data, 'wp.version') ?: null,
            'php_version' => Arr::get($data, 'server.php_version') ?: null,
            'last_seen_at' => now(),
        ];

        if (! $site) {
            return Site::create($attributes + [
                'account_id' => $payload->account_id,
                'project_id' => $payload->project_id,
                'site_key' => $key,
                'status' => Site::STATUS_ACTIVE,
                'first_seen_at' => now(),
            ]);
        }

        // A heartbeat after a deactivation is a reactivation, not a new install.
        if ($site->status !== Site::STATUS_ACTIVE) {
            $attributes['status'] = Site::STATUS_ACTIVE;
            $attributes['deactivated_at'] = null;
        }

        $site->update($attributes);

        return $site;
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

            'client_version' => Arr::get($data, 'client') ?: null,
            'reported_at' => now(),
        ]);
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
