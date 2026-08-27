<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingSkip extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = ['account_id', 'project_id', 'previously_skipped', 'ip'];

    protected function casts(): array
    {
        return ['previously_skipped' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
