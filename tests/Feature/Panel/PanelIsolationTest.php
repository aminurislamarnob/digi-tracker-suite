<?php

namespace Tests\Feature\Panel;

use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * The tenancy boundary as the dashboard sees it.
 *
 * The global scope only bites when an account is in context, and route
 * model binding runs before route middleware -- which is exactly the seam
 * where a panel leaks another account's data. These tests exist to hold
 * that seam shut.
 */
class PanelIsolationTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Account $acme;

    protected Account $globex;

    protected Project $acmeProject;

    protected Project $globexProject;

    protected User $acmeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Account::factory()->create(['name' => 'Acme']);
        $this->globex = Account::factory()->create(['name' => 'Globex']);

        $this->acmeProject = Project::factory()->for($this->acme)->create(['slug' => 'acme-plugin']);
        $this->globexProject = Project::factory()->for($this->globex)->create(['slug' => 'globex-plugin']);

        $this->acmeUser = User::factory()->create();
        $this->acmeUser->accounts()->attach($this->acme, ['role' => 'owner']);
    }

    public function test_another_accounts_project_is_not_reachable_by_url(): void
    {
        $this->actingAs($this->acmeUser)
            ->get(route('projects.overview', $this->globexProject))
            ->assertNotFound();
    }

    public function test_the_project_list_shows_only_our_own(): void
    {
        $this->actingAs($this->acmeUser)->get('/')
            ->assertOk()
            ->assertSee($this->acmeProject->name)
            ->assertDontSee($this->globexProject->name);
    }

    public function test_the_site_list_shows_only_our_own(): void
    {
        $this->track($this->acmeProject, ['url' => 'https://acme-customer.com', 'admin_email' => 'a@acme.com']);
        $this->track($this->globexProject, ['url' => 'https://globex-customer.com', 'admin_email' => 'b@globex.com']);

        $this->actingAs($this->acmeUser)->get(route('projects.sites', $this->acmeProject))
            ->assertOk()
            ->assertSee('acme-customer.com')
            ->assertDontSee('globex-customer.com');
    }

    /**
     * A user removed from an account must stop seeing it, whatever their
     * stored current_account_id still says.
     */
    public function test_a_stale_current_account_is_not_trusted(): void
    {
        $this->acmeUser->forceFill(['current_account_id' => $this->globex->id])->save();

        $this->actingAs($this->acmeUser)->get('/')
            ->assertOk()
            ->assertSee($this->acmeProject->name)
            ->assertDontSee($this->globexProject->name);
    }

    public function test_switching_to_an_account_you_do_not_belong_to_does_nothing(): void
    {
        $this->actingAs($this->acmeUser)
            ->post(route('accounts.switch', $this->globex))
            ->assertRedirect();

        $this->assertNotSame($this->globex->id, $this->acmeUser->fresh()->current_account_id);
    }
}
