<?php

namespace App\Console\Commands;

use App\Contracts\GeoLocator;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Services\DemoTelemetry;
use App\Services\Geo\DemoGeoLocator;
use App\Support\CurrentAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds a demo account with a believable telemetry history.
 *
 * This exists to finish and exercise the implementation before a real
 * plugin ships: the charts, the filters, the rollup and the churn screens
 * all need a population with shape -- an adoption curve, a long tail of PHP
 * versions, sites that go quiet, people who write feedback -- and a handful
 * of hand-posted heartbeats gives none of that.
 *
 * Every project it creates is flagged is_demo, which the panel renders as
 * a banner and which ingest refuses to accept real heartbeats into.
 */
class SeedDemoTelemetry extends Command
{
    protected $signature = 'telemetry:seed-demo
                            {--sites=320 : Total sites to invent across all projects}
                            {--weeks=16 : Weeks of history to generate}
                            {--fresh : Delete the existing demo account first}
                            {--forget : Delete the demo account and seed nothing}
                            {--force : Allow this to run outside a local environment}
                            {--seed= : Make the invented population reproducible}';

    protected $description = 'Create a demo account with invented telemetry, for exercising the dashboard';

    /**
     * PluginizeLab's real portfolio, with install counts scaled down. The
     * relative sizes are kept so the account looks like a real one: one
     * large plugin, one medium, one small pilot, one that has not shipped.
     */
    protected const PROJECTS = [
        [
            'name' => 'WP Change Email Sender',
            'slug' => 'wp-change-email-sender',
            'share' => 0.46,
            'releases' => ['1.4.0', '1.5.0', '1.5.1', '1.6.0'],
        ],
        [
            'name' => 'WP Login and Logout Redirect',
            'slug' => 'wp-login-and-logout-redirect',
            'share' => 0.31,
            'releases' => ['2.0.3', '2.1.0', '2.1.2'],
        ],
        [
            'name' => 'Metadata Viewer',
            'slug' => 'metadata-viewer',
            'share' => 0.23,
            'releases' => ['2.1.0', '2.2.0', '2.2.4', '2.3.0'],
        ],
        [
            'name' => 'StoreSuite',
            'slug' => 'storesuite',
            'share' => 0.0,
            'releases' => ['0.1.0'],
        ],
    ];

    public function handle(): int
    {
        /*
         * The way out, and deliberately the first thing here.
         *
         * Demo data exists to exercise the dashboard before any plugin
         * ships; the moment real heartbeats arrive it is worse than
         * nothing, because an invented population and a measured one render
         * identically. Removing it must be as easy as creating it, must not
         * build the demo machinery on the way, and must never be refused by
         * the environment guard below -- deleting invented rows is the safe
         * direction in every environment there is.
         */
        if ($this->option('forget')) {
            CurrentAccount::withoutScope(fn () => $this->forget());

            return self::SUCCESS;
        }

        /*
         * Local and testing only. Anywhere else -- staging included -- this
         * needs saying out loud, because invented telemetry sitting next to
         * measured telemetry is a problem no later cleanup fully undoes.
         */
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to invent telemetry in the ['.app()->environment().'] environment. Pass --force if you mean it.');

            return self::FAILURE;
        }

        /*
         * Bound for the duration of the seed so the demo travels the real
         * GeoIP path. Writing the country column directly would be simpler
         * and would leave the lookup, its caching and its skip-when-unchanged
         * behaviour entirely untested.
         */
        app()->instance(GeoLocator::class, new DemoGeoLocator);

        // Resolved after the binding, not by method injection on handle():
        // the container would otherwise build SiteReconciler -- and with it
        // the real GeoLocator -- before this method ever runs.
        $demo = app(DemoTelemetry::class);

        return CurrentAccount::withoutScope(function () use ($demo) {
            if ($this->option('fresh')) {
                $this->forget();
            }

            $account = Account::firstOrCreate(
                ['slug' => 'demo'],
                ['name' => 'Demo Agency'],
            );

            $this->attachViewers($account);

            $sites = max(4, (int) $this->option('sites'));
            $weeks = max(1, (int) $this->option('weeks'));

            $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;

            foreach (self::PROJECTS as $index => $definition) {
                // Offset per project, or every plugin gets an identical
                // population and the account looks obviously synthetic.
                $this->build($demo, $account, $definition, $sites, $weeks,
                    $seed !== null ? $seed + $index : null);
            }

            $this->newLine();
            $this->info("Rolling up {$weeks} weeks of history...");

            $this->call('telemetry:build-daily-stats', [
                '--date' => now()->toDateString(),
                '--days' => $weeks * 7,
            ]);

            $this->call('telemetry:classify-sites');

            $this->newLine();
            $this->summarise($account);

            return self::SUCCESS;
        });
    }

    protected function build(DemoTelemetry $demo, Account $account, array $definition, int $sites, int $weeks, ?int $seed = null): void
    {
        $project = Project::acrossAccounts()->firstOrCreate(
            ['account_id' => $account->id, 'slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'is_demo' => true,
                'homepage_url' => 'https://wordpress.org/plugins/'.$definition['slug'].'/',
            ],
        );

        $project->update(['is_demo' => true]);

        $demo->registerMetaFields($project);

        $count = (int) round($sites * $definition['share']);

        if ($count === 0) {
            // StoreSuite has shipped to nobody. A project with no telemetry
            // at all is a state the dashboard has to handle without pretending.
            $this->line("  <fg=gray>{$definition['name']}: no installs yet, nothing to invent</>");

            return;
        }

        $this->newLine();
        $this->line("  <options=bold>{$definition['name']}</> — {$count} sites over {$weeks} weeks");

        $bar = $this->output->createProgressBar($weeks + 1);
        $bar->start();

        $demo->generate(
            $project,
            $count,
            $weeks,
            $definition['releases'],
            fn () => $bar->advance(),
            $seed,
        );

        $bar->finish();
        $this->newLine();
    }

    /**
     * Anyone who can already sign in gets to see the demo account, so it
     * shows up in the tenant switcher without a second login.
     */
    protected function attachViewers(Account $account): void
    {
        User::query()->cursor()->each(
            fn (User $user) => $user->accounts()->syncWithoutDetaching([$account->id => ['role' => 'viewer']]),
        );
    }

    protected function forget(): void
    {
        $account = Account::where('slug', 'demo')->first();

        if (! $account) {
            return;
        }

        // Foreign keys cascade from accounts, so this takes every project,
        // site, report, end user, deactivation and rollup row with it.
        DB::transaction(fn () => $account->delete());

        $this->line('<fg=gray>Removed the previous demo account.</>');
    }

    protected function summarise(Account $account): void
    {
        $rows = Project::acrossAccounts()
            ->where('account_id', $account->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                $project->name,
                number_format($project->sites()->count()),
                number_format($project->reports()->count()),
                number_format($project->deactivations()->count()),
                number_format($project->trackingSkips()->count()),
                number_format($project->dailyStats()->latest('date')->value('active_installs') ?? 0),
            ]);

        $this->table(
            ['Project', 'Sites', 'Reports', 'Deactivations', 'Skips', 'Tracked installs'],
            $rows,
        );

        $this->info('Demo account ready at /admin/demo/projects');
    }
}
