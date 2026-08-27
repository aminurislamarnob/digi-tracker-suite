<?php

namespace Tests\Feature\Panel;

use App\Filament\Pages\Auth\Register;
use App\Models\Account;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Getting in, signing up, and getting back in after forgetting.
 *
 * The interesting half of an auth system is what it refuses, so most of
 * what is asserted here is a refusal: a wrong password, a registration that
 * would leave somebody stranded without an account, a reset token belonging
 * to a different person. Each of those has a failure mode that looks like
 * success from the outside.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function member(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->accounts()->attach(Account::factory()->create(), ['role' => 'owner']);

        return $user;
    }

    /*
     * Login.
     */

    public function test_the_login_screen_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_a_member_can_sign_in(): void
    {
        $user = $this->member(['email' => 'owner@example.com', 'password' => Hash::make('correct-horse')]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@example.com', 'password' => 'correct-horse'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->member(['email' => 'owner@example.com', 'password' => Hash::make('correct-horse')]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@example.com', 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    /**
     * The panel's authorisation rule is membership, not merely having a
     * login. A user belonging to no account has nothing to look at, and
     * seeing an empty dashboard would suggest their data had vanished.
     */
    public function test_a_user_with_no_account_cannot_reach_the_panel(): void
    {
        $stranded = User::factory()->create();

        $this->actingAs($stranded)->get('/admin')->assertForbidden();
    }

    /*
     * Registration.
     */

    public function test_the_registration_screen_is_reachable(): void
    {
        $this->get('/admin/register')->assertOk();
    }

    /**
     * The whole reason this page is ours. Filament's stock registration
     * creates a user and stops, which here produces somebody who signs up
     * successfully and is refused on the very next request.
     */
    public function test_registering_creates_the_account_and_the_membership(): void
    {
        Livewire::test(Register::class)
            ->fillForm([
                'account_name' => 'PluginizeLab',
                'name' => 'Aminur',
                'email' => 'new@example.com',
                'password' => 'a-long-enough-password',
                'passwordConfirmation' => 'a-long-enough-password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $account = Account::query()->where('slug', 'pluginizelab')->firstOrFail();

        $this->assertTrue($user->accounts()->whereKey($account->getKey())->exists());
        $this->assertSame('owner', $user->accounts()->first()->pivot->role);
        $this->assertSame($user->id, $account->owner_id);

        // Signed in, and with somewhere to land rather than a chooser
        // holding a single entry.
        $this->assertAuthenticatedAs($user);
        $this->assertSame($account->id, $user->refresh()->current_account_id);
    }

    /**
     * The slug is the tenant's URL segment and the column is unique, so a
     * collision is not cosmetic: it is the second "Acme" hitting a database
     * error on a form with no field to blame.
     */
    public function test_a_taken_organisation_name_gets_its_own_slug(): void
    {
        Account::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

        Livewire::test(Register::class)
            ->fillForm([
                'account_name' => 'Acme',
                'name' => 'Someone Else',
                'email' => 'second@example.com',
                'password' => 'a-long-enough-password',
                'passwordConfirmation' => 'a-long-enough-password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'acme-2',
            User::query()->where('email', 'second@example.com')->firstOrFail()->accounts()->first()->slug,
        );
    }

    /** A name that slugifies to nothing still has to produce a usable URL. */
    public function test_a_name_with_no_latin_characters_still_gets_a_slug(): void
    {
        Livewire::test(Register::class)
            ->fillForm([
                'account_name' => '株式会社',
                'name' => 'Someone',
                'email' => 'jp@example.com',
                'password' => 'a-long-enough-password',
                'passwordConfirmation' => 'a-long-enough-password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $slug = User::query()->where('email', 'jp@example.com')->firstOrFail()->accounts()->first()->slug;

        $this->assertNotEmpty($slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function test_an_organisation_is_required(): void
    {
        Livewire::test(Register::class)
            ->fillForm([
                'account_name' => '',
                'name' => 'Someone',
                'email' => 'nobody@example.com',
                'password' => 'a-long-enough-password',
                'passwordConfirmation' => 'a-long-enough-password',
            ])
            ->call('register')
            ->assertHasFormErrors(['account_name']);

        // Nothing half-created: no user without an account, ever.
        $this->assertSame(0, User::query()->where('email', 'nobody@example.com')->count());
    }

    public function test_an_address_already_registered_is_refused(): void
    {
        $this->member(['email' => 'taken@example.com']);

        Livewire::test(Register::class)
            ->fillForm([
                'account_name' => 'Another Org',
                'name' => 'Someone',
                'email' => 'taken@example.com',
                'password' => 'a-long-enough-password',
                'passwordConfirmation' => 'a-long-enough-password',
            ])
            ->call('register')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, Account::query()->count());
    }

    /**
     * Registration creates a tenant, so an open form on a public host lets a
     * stranger create an organisation inside the platform. The switch has to
     * actually close the door, not merely hide the link.
     */
    public function test_registration_can_be_closed(): void
    {
        /*
         * Set in the environment and the application rebuilt, not just
         * config() on a booted app. The panel registers its routes while
         * the provider boots, so a setting changed afterwards would leave
         * /admin/register still routed and the test would pass on a page
         * that is in fact still reachable.
         */
        $_ENV['TELEMETRY_REGISTRATION'] = $_SERVER['TELEMETRY_REGISTRATION'] = 'false';
        putenv('TELEMETRY_REGISTRATION=false');

        try {
            $this->refreshApplication();

            $this->assertFalse(config('telemetry.auth.registration'));

            // The route is gone, not merely unlinked. What is left is the
            // panel's own catch-all, which sends a guest to sign in --
            // whatever it answers, it is not a registration form.
            $this->assertFalse(app('router')->has('filament.admin.auth.register'));

            $this->get('/admin/register')->assertRedirect();
        } finally {
            unset($_ENV['TELEMETRY_REGISTRATION'], $_SERVER['TELEMETRY_REGISTRATION']);
            putenv('TELEMETRY_REGISTRATION');
        }
    }

    /*
     * Forgot password, and reset.
     */

    public function test_the_forgotten_password_screen_is_reachable(): void
    {
        $this->get('/admin/password-reset/request')->assertOk();
    }

    public function test_a_reset_link_is_sent(): void
    {
        Notification::fake();

        $user = $this->member(['email' => 'owner@example.com']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'owner@example.com'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    /**
     * An unknown address must not be told it is unknown. A form that answers
     * differently for a registered and an unregistered address is an account
     * enumeration oracle, and this one is public.
     */
    public function test_an_unknown_address_is_answered_the_same_way(): void
    {
        Notification::fake();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'nobody@example.com'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertNothingSent();
    }

    public function test_a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = $this->member(['email' => 'owner@example.com', 'password' => Hash::make('the-old-one')]);

        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm([
                'password' => 'a-brand-new-password',
                'passwordConfirmation' => 'a-brand-new-password',
            ])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password));
    }

    /**
     * A token belongs to one address. Accepting it for another would let
     * anyone who obtained a reset link take over a different account.
     */
    public function test_a_token_issued_for_someone_else_is_refused(): void
    {
        $victim = $this->member(['email' => 'victim@example.com', 'password' => Hash::make('untouched')]);
        $attacker = $this->member(['email' => 'attacker@example.com']);

        $token = Password::broker()->createToken($attacker);

        Livewire::test(ResetPassword::class, ['email' => $victim->email, 'token' => $token])
            ->fillForm([
                'password' => 'taken-over',
                'passwordConfirmation' => 'taken-over',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('untouched', $victim->refresh()->password));
    }

    public function test_a_reset_needs_the_confirmation_to_match(): void
    {
        $user = $this->member(['email' => 'owner@example.com', 'password' => Hash::make('the-old-one')]);

        $token = Password::broker()->createToken($user);

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm([
                'password' => 'a-brand-new-password',
                'passwordConfirmation' => 'something-else',
            ])
            ->call('resetPassword')
            ->assertHasFormErrors(['password']);

        $this->assertTrue(Hash::check('the-old-one', $user->refresh()->password));
    }
}
