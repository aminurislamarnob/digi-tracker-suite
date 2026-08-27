<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'current_account_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)->withPivot('role')->withTimestamps();
    }

    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'current_account_id');
    }

    /**
     * The account this user is looking at, falling back to their first
     * membership. Never trust a stale current_account_id on its own -- a
     * user removed from an account must stop seeing it immediately, which
     * is why membership is re-checked here rather than at assignment time.
     */
    public function resolveCurrentAccount(): ?Account
    {
        $accounts = $this->accounts()->orderBy('accounts.name')->get();

        return $accounts->firstWhere('id', $this->current_account_id) ?? $accounts->first();
    }

    public function belongsToAccount(Account $account): bool
    {
        return $this->accounts()->whereKey($account->getKey())->exists();
    }

    public function switchTo(Account $account): void
    {
        if ($this->belongsToAccount($account)) {
            $this->forceFill(['current_account_id' => $account->id])->save();
        }
    }
}
