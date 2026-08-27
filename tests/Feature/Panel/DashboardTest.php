<?php

namespace Tests\Feature\Panel;

use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create(['name' => 'PluginizeLab']);
        $this->project = Project::factory()->for($this->account)->create([
            'name' => 'Metadata Viewer',
            'slug' => 'metadata-viewer',
        ]);

        $this->user = User::factory()->create();
        $this->user->accounts()->attach($this->account, ['role' => 'owner']);
    }

    public function test_the_dashboard_requires_a_sign_in(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get(route('projects.overview', $this->project))->assertRedirect('/login');
    }

    public function test_the_project_list_shows_the_headline_number(): void
    {
        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 412,
        ]);

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee('Metadata Viewer')
            ->assertSee('412');
    }

    public function test_the_overview_renders_from_the_rollup(): void
    {
        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 300,
            'opted_in' => 3,
            'skipped' => 1,
            'by_php' => ['8.2' => 200, '8.1' => 100],
        ]);

        $this->actingAs($this->user)->get(route('projects.overview', $this->project))
            ->assertOk()
            ->assertSee('Tracked installs')
            ->assertSee('300')
            // The honesty note is part of the product, not decoration.
            ->assertSee('claimed, not proven');
    }

    /**
     * The rollup is the only source charts may read. A project with no
     * daily_stats row must say so rather than quietly rendering zeros as
     * though they were measured.
     */
    public function test_a_project_with_no_rollup_says_so(): void
    {
        $this->actingAs($this->user)->get(route('projects.overview', $this->project))
            ->assertOk()
            ->assertSee('No rollup yet');
    }

    public function test_sites_can_be_filtered(): void
    {
        $this->track($this->project, ['url' => 'https://alpha.com', 'admin_email' => 'a@alpha.com']);
        $this->track($this->project, [
            'url' => 'https://beta.com',
            'admin_email' => 'b@beta.com',
            'project_version' => '3.0.0',
        ]);

        $this->actingAs($this->user)
            ->get(route('projects.sites', $this->project).'?version=3.0.0')
            ->assertOk()
            ->assertSee('beta.com')
            ->assertDontSee('alpha.com');
    }

    /**
     * Addresses are encrypted at rest and reachable only through the blind
     * index, so a partial string must not quietly become a wildcard scan.
     */
    public function test_end_users_are_found_only_by_a_whole_address(): void
    {
        $this->track($this->project, ['admin_email' => 'owner@example.com']);

        $url = route('projects.end-users', $this->project);

        $this->actingAs($this->user)->get($url.'?email=owner@example.com')
            ->assertOk()
            ->assertSee('owner@example.com');

        $this->actingAs($this->user)->get($url.'?email=owner')
            ->assertOk()
            ->assertSee('No end user with that address.');
    }

    public function test_deactivation_comments_are_shown(): void
    {
        $this->track($this->project);
        $this->deactivate($this->project, [
            'reason_id' => 'found-better-plugin',
            'reason_info' => 'Needed multisite support.',
        ]);

        $this->actingAs($this->user)->get(route('projects.deactivations', $this->project))
            ->assertOk()
            ->assertSee('Found a better plugin')
            ->assertSee('Needed multisite support.');
    }
}
