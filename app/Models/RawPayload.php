<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawPayload extends Model
{
    use BelongsToAccount, HasFactory;

    /*
     * The three SDK routes, verbatim. They are baked into every installed
     * copy of every plugin and can never change.
     */
    public const ROUTE_TRACK = 'track';

    public const ROUTE_DEACTIVATE = 'deactivate';

    public const ROUTE_TRACKING_SKIPPED = 'tracking-skipped';

    protected $fillable = [
        'account_id', 'project_id', 'route', 'payload',
        'ip', 'user_agent', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
