<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailEvent extends Model
{
    use BelongsToAccount, HasFactory;

    public const DEACTIVATION_REPLY = 'deactivation_reply';

    public const DEACTIVATION_FORWARD = 'deactivation_forward';

    public const WEEKLY_DIGEST = 'weekly_digest';

    protected $fillable = [
        'account_id', 'project_id', 'end_user_id', 'type',
        'recipient_index', 'sent_at', 'opened_at', 'bounced_at', 'unsubscribed_at',
    ];

    protected $hidden = ['recipient_index'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'bounced_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function endUser(): BelongsTo
    {
        return $this->belongsTo(EndUser::class);
    }
}
