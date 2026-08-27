<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Deactivations\DeactivationResource;
use App\Filament\Resources\EndUsers\EndUserResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\Deactivation;
use App\Models\EndUser;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create(['name' => 'PluginizeLab', 'slug' => 'pluginizelab']);
        $this->project = Project::factory()->for($this->account)->create([
            'name' => 'Metadata Viewer',
            'slug' => 'metadata-viewer',
        ]);

        $this->user = User::factory()->create();
        $this->user->accounts()->attach($this->account, ['role' => 'owner']);
    }

    protected function url(string $resource, string $page = 'index', array $parameters = []): string
    {
        return $resource::getUrl($page, $parameters, tenant: $this->account);
    }

    public function test_the_panel_requires_a_sign_in(): void
    {
        $this->get($this->url(ProjectResource::class))->assertRedirect();
    }

    /**
     * The panel is invitation-only: membership of an account is what grants
     * access, so a user with no memberships has nothing to look at.
     */
    public function test_a_user_with_no_account_cannot_reach_the_panel(): void
    {
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($this->user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_every_resource_renders(): void
    {
        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 412,
        ]);

        $this->actingAs($this->user);

        $this->get($this->url(ProjectResource::class))->assertOk()->assertSee('Metadata Viewer');
        $this->get($this->url(SiteResource::class))->assertOk();
        $this->get($this->url(EndUserResource::class))->assertOk();
        $this->get($this->url(DeactivationResource::class))->assertOk();
    }

    public function test_the_project_overview_renders_and_states_the_caveat(): void
    {
        $this->actingAs($this->user)
            ->get($this->url(ProjectResource::class, 'view', ['record' => $this->project]))
            ->assertOk()
            // The honesty note is part of the product, not decoration.
            ->assertSee('claimed, not proven', escape: false);
    }

    /**
     * Telemetry is a record of what sites reported. Editing a row would put
     * the dashboard at odds with the data that produced it, and the next
     * heartbeat would overwrite the edit anyway.
     */
    public function test_telemetry_resources_are_read_only(): void
    {
        $resources = [
            SiteResource::class => new Site,
            EndUserResource::class => new EndUser,
            DeactivationResource::class => new Deactivation,
        ];

        foreach ($resources as $resource => $record) {
            $this->assertFalse($resource::canCreate(), $resource.' must not be creatable');
            $this->assertFalse($resource::canEdit($record), $resource.' must not be editable');
        }
    }
}
