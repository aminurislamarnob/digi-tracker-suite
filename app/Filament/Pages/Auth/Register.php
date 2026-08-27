<?php

namespace App\Filament\Pages\Auth;

use App\Models\Account;
use App\Models\User;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Registration, which here means creating two things at once.
 *
 * The panel's authorisation rule is membership: `User::canAccessPanel()`
 * asks whether the user belongs to any account, because a user with no
 * account has nothing to look at and Filament renders that as a refusal
 * rather than an empty dashboard. Filament's stock registration creates a
 * user and nothing else -- so a person would sign up successfully, be
 * logged in, and be shown the door on the same request.
 *
 * So this form asks for an organisation as well, and one submission creates
 * the user, the account, and the membership that joins them. In a
 * transaction, because a user stranded without an account is exactly the
 * state described above, and it would need a shell to repair.
 *
 * Note what this does *not* do: it never asks which existing account to
 * join. Joining an account someone else owns is an invitation they issue,
 * never a box a stranger types a name into.
 */
class Register extends BaseRegister
{
    /*
     * Explicit rather than inherited from the panel. Three writes have to
     * land together here, and the failure mode of a half-applied
     * registration is the one this page exists to avoid.
     */
    protected ?bool $hasDatabaseTransactions = true;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * First, and deliberately. It is the thing being created --
             * the account is the tenant, the security boundary, and the
             * name in the URL from here on.
             */
            TextInput::make('account_name')
                ->label('Organisation')
                ->required()
                ->maxLength(255)
                ->autofocus()
                ->helperText('Your projects and telemetry live under this. It can be renamed later.'),

            $this->getNameFormComponent()->autofocus(false),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $account = Account::create([
            'name' => $data['account_name'],
            'slug' => $this->uniqueSlug($data['account_name']),
            'owner_id' => $user->id,
        ]);

        $user->accounts()->attach($account, ['role' => 'owner']);

        // So the first request after signing in has a tenant to land on
        // rather than a chooser with one entry.
        $user->forceFill(['current_account_id' => $account->id])->save();

        return $user;
    }

    /**
     * A slug nobody else holds.
     *
     * The column is unique and the slug is the tenant's URL segment, so a
     * collision is not a cosmetic problem -- it is the second registration
     * of "Acme" failing with a database error on a form that had no way to
     * warn about it. Suffixed instead.
     *
     * Falls back to a random string when the name slugifies to nothing,
     * which every non-Latin organisation name does.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 1;

        while (Account::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
