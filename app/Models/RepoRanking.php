<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's search position for one keyword on one day.
 *
 * A null position means "not found within searched_depth", which is not a
 * bad rank -- it is the absence of one, and must never be averaged as a
 * number.
 */
class RepoRanking extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'project_id', 'repo_keyword_id',
        'captured_on', 'position', 'searched_depth', 'total_results',
    ];

    protected function casts(): array
    {
        return ['captured_on' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(RepoKeyword::class, 'repo_keyword_id');
    }

    public function isRanked(): bool
    {
        return $this->position !== null;
    }
}
