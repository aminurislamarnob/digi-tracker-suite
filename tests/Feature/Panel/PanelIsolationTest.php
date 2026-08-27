<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Account;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * The tenancy boundary as the panel sees it.
 *
 * Two mechanisms have to agree: Filament scopes the queries it builds
 * through the tenant relationship, and our own global scope catches
 * anything queried directly. These tests exist because a leak needs only
 * one of the two to be missing.
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

        $this->acme = Account::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->globex = Account::factory()->create(['name' => 'Globex', 'slug' => 'globex']);

        $this->acmeProject = Project::factory()->for($this->acme)->create([
            'name' => 'Acme Plugin', 'slug' => 'acme-plugin',
        ]);
        $this->globexProject = Project::factory()->for($this->globex)->create([
            'name' => 'Globex Plugin', 'slug' => 'globex-plugin',
        ]);

        $this->acmeUser = User::factory()->create();
        $this->acmeUser->accounts()->attach($this->acme, ['role' => 'owner']);
    }

    /**
     * Filament answers 404 rather than 403, which is the better of the two:
     * a 403 would confirm the account exists.
     */
    public function test_an_account_you_do_not_belong_to_is_refused(): void
    {
        $this->actingAs($this->acmeUser)
            ->get(ProjectResource::getUrl('index', tenant: $this->globex))
            ->assertNotFound();
    }

    /**
     * Membership is re-checked on every request, so removing somebody from
     * an account locks them out immediately rather than at next login.
     */
    public function test_membership_is_rechecked_per_request(): void
    {
        $this->assertTrue($this->acmeUser->canAccessTenant($this->acme));
        $this->assertFalse($this->acmeUser->canAccessTenant($this->globex));

        $this->acmeUser->accounts()->detach($this->acme);

        $this->assertFalse($this->acmeUser->fresh()->canAccessTenant($this->acme));
    }

    public function test_a_suspended_account_is_refused(): void
    {
        $this->acme->update(['is_suspended' => true]);

        $this->assertFalse($this->acmeUser->canAccessTenant($this->acme));
    }

    public function test_the_project_list_shows_only_our_own(): void
    {
        $this->actingAs($this->acmeUser)
            ->get(ProjectResource::getUrl('index', tenant: $this->acme))
            ->assertOk()
            ->assertSee('Acme Plugin')
            ->assertDontSee('Globex Plugin');
    }

    public function test_the_site_table_shows_only_our_own(): void
    {
        $this->track($this->acmeProject, ['url' => 'https://acme-customer.com', 'admin_email' => 'a@acme.com']);
        $this->track($this->globexProject, ['url' => 'https://globex-customer.com', 'admin_email' => 'b@globex.com']);

        $this->actingAs($this->acmeUser)
            ->get(SiteResource::getUrl('index', tenant: $this->acme))
            ->assertOk()
            ->assertSee('acme-customer.com')
            ->assertDontSee('globex-customer.com');
    }

    /**
     * Guessing a record id must not work either. Filament scopes the query
     * that resolves the record, so another account's site is simply absent.
     */
    public function test_another_accounts_site_is_not_reachable_by_id(): void
    {
        $this->track($this->globexProject, ['url' => 'https://globex-customer.com', 'admin_email' => 'b@globex.com']);

        $globexSite = Site::acrossAccounts()->sole();

        $this->actingAs($this->acmeUser)
            ->get(SiteResource::getUrl('view', ['record' => $globexSite->id], tenant: $this->acme))
            ->assertNotFound();
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }
}
