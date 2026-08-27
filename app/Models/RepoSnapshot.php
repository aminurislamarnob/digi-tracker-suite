<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's public record for a project, as wordpress.org reported it.
 */
class RepoSnapshot extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'project_id', 'captured_on',
        'active_installs', 'downloaded',
        'rating', 'num_ratings', 'ratings',
        'support_threads', 'support_threads_resolved',
        'version', 'requires', 'requires_php', 'tested',
        'last_updated_at', 'version_distribution',
    ];

    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'last_updated_at' => 'datetime',
            'ratings' => 'array',
            'version_distribution' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The share of support threads marked resolved.
     *
     * Null rather than 100 when nobody has asked anything: a plugin with no
     * threads has not resolved all of them, it has no record either way.
     */
    public function resolutionRate(): ?float
    {
        if (! $this->support_threads) {
            return null;
        }

        return round($this->support_threads_resolved / $this->support_threads * 100, 1);
    }
}
