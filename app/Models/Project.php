<?php

namespace App\Models;

use App\Jobs\RefreshRepoStats;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Project extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'hash', 'name', 'slug', 'wporg_slug', 'type',
        'homepage_url', 'demo_url', 'description', 'icon_path', 'is_active', 'is_demo',
        'from_name', 'reply_to', 'support_email', 'email_footer',
        'replies_to_deactivations', 'forwards_deactivations', 'sends_weekly_digest',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'replies_to_deactivations' => 'boolean',
            'forwards_deactivations' => 'boolean',
            'sends_weekly_digest' => 'boolean',
        ];
    }

    /**
     * Slugs in dashboard URLs, never ids.
     *
     * The slug is unique per account, so it says nothing about how many
     * projects exist across the platform -- and it makes a link readable
     * enough to paste into a ticket. The hash stays out of URLs entirely:
     * it is the ingest routing key, and it belongs in a request body.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->hash ??= (string) Str::uuid();
        });

        // A project with no reason list would record deactivations it can
        // never label, so the SDK's seven are seeded on day one.
        static::created(fn (Project $project) => $project->seedDefaultReasons());

        static::saved(fn (Project $project) => $project->refreshRepositoryOnLink());
    }

    /**
     * Fetch the public record the moment a project is pointed at one.
     *
     * Without this a newly linked project waits until 03:00 for its first
     * capture, and until then the repository page has live download totals
     * at the top -- those come straight from wordpress.org -- above a chart
     * reading an empty table. The page says "no downloads recorded", which
     * is true and reads exactly like a bug.
     *
     * Fires on the link, not on every save: changing a project's name must
     * not re-fetch, and neither must clearing the slug, which leaves
     * nothing to fetch.
     */
    protected function refreshRepositoryOnLink(): void
    {
        /*
         * Both conditions are needed. On insert Eloquent has nothing to
         * compare against, so wasChanged() is false however the slug
         * arrived; on update wasRecentlyCreated is false. Neither alone
         * covers creating a project with a slug already filled in, which is
         * the common case -- the field sits on the same form as the name.
         */
        if (! $this->wasRecentlyCreated && ! $this->wasChanged('wporg_slug')) {
            return;
        }

        if ($this->is_demo || ! $this->isOnRepository()) {
            return;
        }

        /*
         * The same key the Refresh button claims, so the two throttle each
         * other. Linking a project and immediately pressing Refresh is one
         * fetch, not two -- and a slug corrected twice in a minute, which is
         * what a typo looks like, does not become two full captures.
         */
        $key = RefreshRepoStats::cooldownKey($this->id);

        if (! Cache::add($key, true, RefreshRepoStats::COOLDOWN_SECONDS)) {
            return;
        }

        /*
         * After the commit, because the worker reads this project back by
         * id. Dispatched inside an uncommitted transaction the job can
         * start before the row is visible, find nothing, and return having
         * quietly done nothing at all.
         */
        RefreshRepoStats::dispatch($this->id)->afterCommit();
    }

    public function seedDefaultReasons(): void
    {
        foreach (DeactivationReason::DEFAULTS as $order => $reason) {
            DeactivationReason::acrossAccounts()->firstOrCreate(
                ['project_id' => $this->id, 'reason_id' => $reason['reason_id']],
                $reason + ['account_id' => $this->account_id, 'sort_order' => $order],
            );
        }
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function endUsers(): HasMany
    {
        return $this->hasMany(EndUser::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(SiteReport::class);
    }

    public function metaFields(): HasMany
    {
        return $this->hasMany(ProjectMetaField::class);
    }

    public function deactivationReasons(): HasMany
    {
        return $this->hasMany(DeactivationReason::class)->orderBy('sort_order');
    }

    public function deactivations(): HasMany
    {
        return $this->hasMany(Deactivation::class);
    }

    public function trackingSkips(): HasMany
    {
        return $this->hasMany(TrackingSkip::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyStat::class);
    }

    /*
     * The public half. A project without a wporg_slug is not broken -- a
     * private or unpublished plugin still collects telemetry perfectly
     * well; it simply has nothing public to compare itself against.
     */

    public function repoSnapshots(): HasMany
    {
        return $this->hasMany(RepoSnapshot::class);
    }

    /**
     * The most recent capture, for list screens.
     *
     * Snapshots are append-only -- one row per project per day -- so the
     * "current" public figure is always the newest row rather than a column
     * anywhere. A list page reading that per row would issue a query per
     * project; latestOfMany makes it one eager load for the whole page.
     */
    public function latestRepoSnapshot(): HasOne
    {
        return $this->hasOne(RepoSnapshot::class)->latestOfMany('captured_on');
    }

    public function repoDownloads(): HasMany
    {
        return $this->hasMany(RepoDownload::class);
    }

    public function repoReleases(): HasMany
    {
        return $this->hasMany(RepoRelease::class);
    }

    public function repoKeywords(): HasMany
    {
        return $this->hasMany(RepoKeyword::class);
    }

    public function repoRankings(): HasMany
    {
        return $this->hasMany(RepoRanking::class);
    }

    public function isOnRepository(): bool
    {
        return filled($this->wporg_slug);
    }
}
