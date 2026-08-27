<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A search term whose repository ranking is tracked. */
class RepoKeyword extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = ['account_id', 'project_id', 'keyword', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Normalised on the way in, so "Email Sender" and "email sender"
        // cannot become two terms that disagree on the same chart.
        static::saving(function (RepoKeyword $keyword) {
            $keyword->keyword = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $keyword->keyword)));
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(RepoRanking::class);
    }
}
