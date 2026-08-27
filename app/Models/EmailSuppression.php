<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailSuppression extends Model
{
    use BelongsToAccount, HasFactory;

    public const UNSUBSCRIBED = 'unsubscribed';

    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    public const MANUAL = 'manual';

    protected $fillable = ['account_id', 'email_index', 'reason'];

    protected $hidden = ['email_index'];
}
