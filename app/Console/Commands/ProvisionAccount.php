<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an account and its first owner.
 *
 * The panel is invitation-only and has no registration page -- membership of
 * an account is what grants access -- so on a fresh deployment there is no
 * way in at all until this has run. make:filament-user is not a substitute:
 * it produces a user with no tenant, whom canAccessPanel() correctly turns
 * away.
 *
 * The password is prompted for rather than passed as an option, so it never
 * reaches shell history, a process list, or a deploy log.
 */
class ProvisionAccount extends Command
{
    protected $signature = 'telemetry:provision-account
                            {--account= : Display name for the account}
                            {--slug= : URL slug, derived from the name if omitted}
                            {--name= : The owner\'s name}
                            {--email= : The owner\'s email address}';

    protected $description = 'Create an account and its first owner';

    public function handle(): int
    {
        /*
         * Without a terminal there is nothing to type a hidden password into,
         * and secret() returns null rather than failing. The validator then
         * reports "The password field is required", which sends you looking
         * for a missing option that does not exist.
         *
         * This is easy to hit: `ssh host 'artisan ...'` allocates no TTY.
         * The fix is `ssh -t`, so say that rather than making it a puzzle.
         */
        if (! $this->input->isInteractive()) {
            $this->error('This command needs a terminal, because it prompts for a password.');
            $this->newLine();
            $this->line('  Run it over SSH with a TTY attached:');
            $this->newLine();
            $this->line('    <fg=cyan>ssh -t '.gethostname().' \'cd '.base_path().' && php artisan '.$this->getName().' ...\'</>');
            $this->newLine();
            $this->line('  <fg=gray>The password is deliberately not an option: an option would</>');
            $this->line('  <fg=gray>put it in shell history, the process list, and any deploy log.</>');
            $this->newLine();

            return self::FAILURE;
        }

        $accountName = $this->option('account') ?: $this->ask('Account name');
        $slug = Str::slug($this->option('slug') ?: $accountName);
        $ownerName = $this->option('name') ?: $this->ask("Owner's name");
        $email = $this->option('email') ?: $this->ask("Owner's email");

        $password = $this->secret("Owner's password");

        if ($password !== $this->secret('Confirm password')) {
            $this->error('The passwords did not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            compact('accountName', 'slug', 'ownerName', 'email', 'password'),
            [
                'accountName' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255'],
                'ownerName' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /*
         * Both models are written outside the tenant scope: there is no
         * current account yet, and the global scope would otherwise filter
         * the account being created out of its own existence check.
         */
        return CurrentAccount::withoutScope(function () use ($accountName, $slug, $ownerName, $email, $password) {
            if (Account::where('slug', $slug)->exists()) {
                $this->error("An account already uses the slug \"{$slug}\".");

                return self::FAILURE;
            }

            $user = User::where('email', $email)->first();

            if ($user) {
                // Adding an existing person to a new account is legitimate;
                // silently resetting their password would not be.
                $this->line("  <fg=gray>{$email} already exists -- adding them to the new account, password unchanged.</>");
            }

            DB::transaction(function () use (&$user, $accountName, $slug, $ownerName, $email, $password) {
                $user ??= User::create([
                    'name' => $ownerName,
                    'email' => $email,
                    'password' => $password,
                ]);

                $account = Account::create([
                    'name' => $accountName,
                    'slug' => $slug,
                    'owner_id' => $user->id,
                ]);

                $user->accounts()->syncWithoutDetaching([
                    $account->id => ['role' => 'owner'],
                ]);

                $user->forceFill(['current_account_id' => $account->id])->save();
            });

            $this->newLine();
            $this->info('Account provisioned.');
            $this->components->twoColumnDetail('Account', $accountName);
            $this->components->twoColumnDetail('Owner', $email);
            $this->components->twoColumnDetail('Sign in at', url('/admin/'.$slug));
            $this->newLine();

            return self::SUCCESS;
        });
    }
}
