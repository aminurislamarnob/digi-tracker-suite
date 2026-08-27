<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** When a version shipped, and how confidently we know it. */
class RepoRelease extends Model
{
    use BelongsToAccount, HasFactory;

    /** Read from the Subversion tag itself. Authoritative. */
    public const FROM_SVN = 'svn';

    /** Inferred from the version field changing. An upper bound only. */
    public const FROM_OBSERVATION = 'observed';

    protected $fillable = ['account_id', 'project_id', 'version', 'released_on', 'source'];

    protected function casts(): array
    {
        return ['released_on' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isExact(): bool
    {
        return $this->source === self::FROM_SVN;
    }
}
