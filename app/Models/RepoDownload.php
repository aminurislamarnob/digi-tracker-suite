<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day's download count from the repository. */
class RepoDownload extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = ['account_id', 'project_id', 'date', 'downloads'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
