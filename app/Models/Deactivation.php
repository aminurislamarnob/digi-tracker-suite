<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deactivation extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'project_id', 'site_id',
        'reason_id', 'reason_info', 'project_version',
        'theme_slug', 'theme_name', 'theme_version', 'reactivated_at',
    ];

    protected function casts(): array
    {
        return ['reactivated_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Deliberately not a belongsTo on reason_id: that key is only unique
     * per project, so the relation would happily resolve a label from
     * another account's project. The dashboard resolves labels through the
     * project's own reason list instead.
     */
    public function reasonLabel(): ?string
    {
        if (! $this->reason_id) {
            return null;
        }

        return DeactivationReason::acrossAccounts()
            ->where('project_id', $this->project_id)
            ->where('reason_id', $this->reason_id)
            ->value('label') ?? $this->reason_id;
    }
}
