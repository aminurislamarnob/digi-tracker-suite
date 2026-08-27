<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Deactivation;
use App\Models\Project;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * Demo data is the one place this application manufactures numbers, so the
 * risk it carries is not that it breaks -- it is that it looks real.
 */
class DemoTelemetryTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected function seed_demo(int $sites = 40, int $weeks = 4): void
    {
        $this->artisan('telemetry:seed-demo', [
            '--sites' => $sites,
            '--weeks' => $weeks,
            '--fresh' => true,
            // Fixed, so "the population has churn with written feedback" is
            // an assertion about the generator rather than about luck.
            '--seed' => 20260828,
        ])->assertSuccessful();
    }

    public function test_it_builds_a_flagged_account_with_history(): void
    {
        $this->seed_demo();

        $account = Account::where('slug', 'demo')->sole();
        $projects = Project::acrossAccounts()->where('account_id', $account->id)->get();

        $this->assertCount(4, $projects);
        $this->assertTrue($projects->every->is_demo, 'every demo project must carry the flag');

        $this->assertGreaterThan(0, Site::acrossAccounts()->count());
        $this->assertGreaterThan(0, SiteReport::acrossAccounts()->count());
        $this->assertGreaterThan(0, DailyStat::acrossAccounts()->count());
    }

    /**
     * The whole safeguard. If a real heartbeat could land in a demo project
     * then nobody could say afterwards which rows had been measured.
     */
    public function test_ingest_refuses_a_demo_project(): void
    {
        $this->seed_demo();

        $project = Project::acrossAccounts()->where('is_demo', true)->first();

        $this->postTelemetry('/track', $this->payload($project))->assertNotFound();
        $this->postTelemetry('/deactivate', $this->payload($project))->assertNotFound();
        $this->post('/tracking-skipped', ['hash' => $project->hash])->assertNotFound();
    }

    public function test_a_real_project_still_accepts_telemetry(): void
    {
        $this->seed_demo();

        $real = Project::factory()->create(['is_demo' => false]);

        $this->postTelemetry('/track', $this->payload($real))->assertOk();
    }

    /**
     * Generated through SiteReconciler rather than inserted, so the wire
     * format's traps have to be handled for any of this to be right: "No"
     * is false, counts are strings, unregistered extra[] keys are dropped.
     */
    public function test_the_generated_data_went_through_the_real_pipeline(): void
    {
        $this->seed_demo();

        $report = SiteReport::acrossAccounts()->whereNotNull('users_by_role')->first();

        $this->assertIsInt($report->users_by_role['administrator']);
        $this->assertIsBool($report->multisite);

        // 'undeclared' is in every generated payload and in no whitelist.
        $extra = SiteReport::acrossAccounts()->whereNotNull('extra')->value('extra');

        $this->assertArrayNotHasKey('undeclared', (array) $extra);

        $this->assertGreaterThan(0, SitePlugin::acrossAccounts()->count());
    }

    /**
     * A flat population would exercise none of the analytics. The point of
     * this data is that it has shape: an environment spread, real churn,
     * and feedback somebody actually wrote.
     */
    public function test_the_population_has_shape(): void
    {
        $this->seed_demo(sites: 80, weeks: 8);

        $stat = DailyStat::acrossAccounts()
            ->whereNotNull('by_php')
            ->orderByDesc('active_installs')
            ->first();

        $this->assertGreaterThan(2, count($stat->by_php), 'PHP versions should spread');
        $this->assertGreaterThan(2, count($stat->by_country), 'countries should spread');
        $this->assertGreaterThan(0, Deactivation::acrossAccounts()->count());
        $this->assertGreaterThan(
            0,
            Deactivation::acrossAccounts()->whereNotNull('reason_info')->count(),
            'some people should have written something',
        );
    }

    public function test_the_panel_says_the_data_is_invented(): void
    {
        $this->seed_demo();

        $account = Account::where('slug', 'demo')->sole();

        $user = User::factory()->create();
        $user->accounts()->syncWithoutDetaching([$account->id => ['role' => 'owner']]);

        $this->actingAs($user)
            ->get(ProjectResource::getUrl('index', tenant: $account))
            ->assertOk()
            ->assertSee('Demo data', escape: false);
    }

    public function test_it_refuses_to_run_outside_local_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('telemetry:seed-demo')->assertFailed();

        $this->assertDatabaseMissing('accounts', ['slug' => 'demo']);
    }
}
