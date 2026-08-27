<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'current_account_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
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
     * The panel is invitation-only, so access is membership of at least one
     * account rather than a flag on the user. A user with no memberships has
     * nothing to look at, and Filament renders that as a refusal rather than
     * as an empty dashboard.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->accounts()->exists();
    }

    /**
     * @return Collection<int, Account>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->accounts()->orderBy('accounts.name')->get();
    }

    /**
     * Checked on every request Filament serves, so removing somebody from an
     * account locks them out immediately rather than at their next login.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Account
            && ! $tenant->is_suspended
            && $this->accounts()->whereKey($tenant->getKey())->exists();
    }
}
