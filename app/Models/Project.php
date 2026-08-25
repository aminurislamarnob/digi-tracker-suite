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

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->hash ??= (string) Str::uuid();
        });
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
}
