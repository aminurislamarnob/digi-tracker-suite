<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * The panel has no registration page, so this command is the only door into
 * a fresh deployment. If it is wrong, nobody can sign in at all.
 */
class ProvisionAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function provision(array $options = [], string $password = 'correct-horse-battery'): PendingCommand
    {
        return $this->artisan('telemetry:provision-account', array_merge([
            '--account' => 'PluginizeLab',
            '--slug' => 'pluginizelab',
            '--name' => 'Aminur',
            '--email' => 'owner@pluginizelab.com',
        ], $options))
            ->expectsQuestion("Owner's password", $password)
            ->expectsQuestion('Confirm password', $password);
    }

    public function test_it_creates_an_account_with_an_owner_who_can_reach_the_panel(): void
    {
        $this->provision()->assertSuccessful();

        $account = Account::query()->firstOrFail();
        $user = User::firstOrFail();

        $this->assertSame('pluginizelab', $account->slug);
        $this->assertSame($user->id, $account->owner_id);
        $this->assertSame('owner', $user->accounts()->first()->pivot->role);
        $this->assertSame($account->id, $user->current_account_id);

        // Membership is what canAccessPanel() checks, so assert the thing
        // itself rather than the pivot row standing in for it.
        $this->assertTrue($user->canAccessPanel(app(Panel::class)));
    }

    public function test_the_password_is_hashed_not_stored(): void
    {
        $this->provision(password: 'correct-horse-battery')->assertSuccessful();

        $user = User::firstOrFail();

        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
    }

    public function test_mismatched_passwords_create_nothing(): void
    {
        $this->artisan('telemetry:provision-account', [
            '--account' => 'PluginizeLab',
            '--name' => 'Aminur',
            '--email' => 'owner@pluginizelab.com',
        ])
            ->expectsQuestion("Owner's password", 'correct-horse-battery')
            ->expectsQuestion('Confirm password', 'something-else-entirely')
            ->assertFailed();

        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, User::count());
    }

    /**
     * A short password on the one account that can see every tenant's
     * telemetry is not a warning, it is a refusal.
     */
    public function test_a_weak_password_is_refused(): void
    {
        $this->provision(password: 'short')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_a_duplicate_slug_is_refused(): void
    {
        $this->provision()->assertSuccessful();

        $this->provision(['--email' => 'someone@else.com'])->assertFailed();

        $this->assertSame(1, Account::query()->count());
    }

    /**
     * Adding an existing person to a second account is legitimate. Quietly
     * resetting their password while doing it would not be.
     */
    public function test_an_existing_user_keeps_their_password(): void
    {
        $this->provision()->assertSuccessful();

        $original = User::firstOrFail()->password;

        $this->provision([
            '--account' => 'Second Org',
            '--slug' => 'second-org',
        ], password: 'a-completely-different-one')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame($original, User::firstOrFail()->password);
        $this->assertSame(2, User::firstOrFail()->accounts()->count());
    }
}
