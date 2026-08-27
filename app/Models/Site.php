<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use BelongsToAccount, HasFactory;

    /*
     * Three states, where Appsero has two. They fold a deactivation into
     * "inactive"; we keep them apart because "told us they were leaving"
     * and "stopped reporting" are different signals with different fixes.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DEACTIVATED = 'deactivated';

    /*
     * The heartbeat is weekly, so silence is not immediately meaningful --
     * a site with a broken wp-cron looks identical to one that vanished.
     * Thirty days is four missed beats: slow enough to avoid false churn,
     * fast enough to be useful.
     */
    public const ACTIVE_WINDOW_DAYS = 30;

    public const INACTIVE_WINDOW_DAYS = 90;

    public static function activeWindowDays(): int
    {
        return (int) config('telemetry.active_window_days', self::ACTIVE_WINDOW_DAYS);
    }

    public static function inactiveWindowDays(): int
    {
        return (int) config('telemetry.inactive_window_days', self::INACTIVE_WINDOW_DAYS);
    }

    protected $fillable = [
        'account_id', 'project_id', 'end_user_id', 'site_key', 'url', 'canonical_url',
        'ua_fingerprint', 'name', 'ip', 'country', 'is_local',
        'current_version', 'wp_version', 'php_version', 'status',
        'first_seen_at', 'last_seen_at', 'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function endUser(): BelongsTo
    {
        return $this->belongsTo(EndUser::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(SiteReport::class);
    }

    public function plugins(): HasMany
    {
        return $this->hasMany(SitePlugin::class);
    }

    public function deactivations(): HasMany
    {
        return $this->hasMany(Deactivation::class);
    }
}
