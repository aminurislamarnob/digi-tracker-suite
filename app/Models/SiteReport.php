<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteReport extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'project_id', 'site_id', 'project_version',
        'wp_version', 'php_version', 'mysql_version', 'server_software',
        'locale', 'multisite', 'memory_limit', 'debug_mode',
        'theme_slug', 'theme_name', 'theme_version',
        'users_total', 'users_by_role', 'active_plugins', 'inactive_plugins',
        'extra', 'client_version', 'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'users_by_role' => 'array',
            'extra' => 'array',
            'multisite' => 'boolean',
            'debug_mode' => 'boolean',
            'reported_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
