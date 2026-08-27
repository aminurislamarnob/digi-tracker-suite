<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'hash', 'name', 'slug', 'type',
        'homepage_url', 'demo_url', 'description', 'icon_path', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
}
