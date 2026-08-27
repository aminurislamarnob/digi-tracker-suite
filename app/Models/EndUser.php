<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class EndUser extends Model
{
    use BelongsToAccount, HasFactory;

    protected $fillable = [
        'account_id', 'project_id', 'email', 'email_index',
        'first_name', 'last_name', 'marketing_consent_at',
        'first_seen_at', 'last_seen_at',
    ];

    protected $hidden = ['email_index'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'marketing_consent_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Keyed hash of the normalised address, so an encrypted column can still
     * be looked up by exact match. Uses the app key, so it is not reversible
     * by anyone holding only the database.
     */
    public static function indexFor(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function hasMarketingConsent(): bool
    {
        return $this->marketing_consent_at !== null;
    }
}
