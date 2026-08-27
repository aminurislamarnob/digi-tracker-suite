<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    use BelongsToAccount, HasFactory;

    /** The open-ended distributions, all shaped {value: count}. */
    public const DISTRIBUTIONS = [
        'by_version', 'by_php', 'by_wp', 'by_mysql',
        'by_locale', 'by_server', 'by_theme', 'by_multisite', 'by_country',
    ];

    protected $fillable = [
        'account_id', 'project_id', 'date',
        'active_installs', 'new_installs', 'deactivations', 'reactivations',
        'opted_in', 'skipped',
        ...self::DISTRIBUTIONS,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'by_version' => 'array',
            'by_php' => 'array',
            'by_wp' => 'array',
            'by_mysql' => 'array',
            'by_locale' => 'array',
            'by_server' => 'array',
            'by_theme' => 'array',
            'by_multisite' => 'array',
            'by_country' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
