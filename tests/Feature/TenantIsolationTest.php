<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteReport;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The isolation boundary, tested before anything is built on top of it.
 *
 * The hash is the only routing key the protocol offers and it is visible
 * in GPL source. If a forged or guessed hash could select an account, or
 * if a query could quietly cross the boundary, every other guarantee in
 * this application is void.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Account $acme;

    protected Account $globex;

    protected Project $acmeProject;

    protected Project $globexProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Account::factory()->create(['name' => 'Acme']);
        $this->globex = Account::factory()->create(['name' => 'Globex']);

        $this->acmeProject = Project::factory()->for($this->acme)->create();
        $this->globexProject = Project::factory()->for($this->globex)->create();

        $this->heartbeat($this->acmeProject, 'https://acme-customer.com');
        $this->heartbeat($this->globexProject, 'https://globex-customer.com');
    }

    protected function heartbeat(Project $project, string $url): void
    {
        $this->post('/track', [
            'hash' => $project->hash,
            'url' => $url,
            'project_version' => '1.0.0',
            'admin_email' => 'owner@'.parse_url($url, PHP_URL_HOST),
            'is_local' => '',
        ])->assertOk();
    }

    public function test_each_account_sees_only_its_own_sites(): void
    {
        CurrentAccount::set($this->acme);

        $this->assertSame(1, Site::count());
        $this->assertSame('acme-customer.com', Site::sole()->canonical_url);

        CurrentAccount::set($this->globex);

        $this->assertSame(1, Site::count());
        $this->assertSame('globex-customer.com', Site::sole()->canonical_url);
    }

    public function test_an_account_cannot_read_another_accounts_record_by_id(): void
    {
        $globexSiteId = CurrentAccount::withoutScope(
            fn () => Site::where('account_id', $this->globex->id)->sole()->id,
        );

        CurrentAccount::set($this->acme);

        $this->assertNull(Site::find($globexSiteId));
        $this->assertSame(0, Site::whereKey($globexSiteId)->count());
    }

    public function test_reports_and_projects_are_scoped_too(): void
    {
        CurrentAccount::set($this->acme);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, SiteReport::count());
        $this->assertSame($this->acmeProject->id, Project::sole()->id);
    }

    /**
     * Ingest runs with no account in context -- the payload arrives before
     * we know whose it is. The account must come from the project record,
     * never from anything the caller sent.
     */
    public function test_ingest_assigns_the_account_from_the_project_not_the_payload(): void
    {
        $this->post('/track', [
            'hash' => $this->acmeProject->hash,
            'url' => 'https://forged.com',
            'project_version' => '1.0.0',
            'account_id' => $this->globex->id,
            'is_local' => '',
        ])->assertOk();

        $site = CurrentAccount::withoutScope(
            fn () => Site::where('canonical_url', 'forged.com')->sole(),
        );

        $this->assertSame($this->acme->id, $site->account_id);
    }

    public function test_new_records_are_stamped_with_the_account_in_context(): void
    {
        CurrentAccount::set($this->globex);

        $project = Project::create(['name' => 'Scoped', 'slug' => 'scoped']);

        $this->assertSame($this->globex->id, $project->account_id);
    }

    protected function tearDown(): void
    {
        CurrentAccount::clear();

        parent::tearDown();
    }
}
