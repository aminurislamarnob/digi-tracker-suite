<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Deactivations\DeactivationResource;
use App\Filament\Resources\EndUsers\EndUserResource;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\DeactivationReasonsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\MetaFieldsRelationManager;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Account;
use App\Models\Project;
use App\Models\ProjectMetaField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create(['slug' => 'acme']);
        $this->project = Project::factory()->for($this->account)->create(['slug' => 'widget']);

        $this->user = User::factory()->create();
        $this->user->accounts()->attach($this->account, ['role' => 'owner']);

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Filament::setTenant(null);

        parent::tearDown();
    }

    protected function seedSites(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->track($this->project, [
                'url' => "https://site-{$i}.com",
                'admin_email' => "owner{$i}@site-{$i}.com",
                'plugins' => ['woocommerce' => ['name' => 'WooCommerce', 'version' => '9.4.2']],
            ])->assertOk();
        }
    }

    public function test_the_reports_page_renders(): void
    {
        $this->seedSites(3);

        $this->get(ProjectResource::getUrl('reports', ['record' => $this->project], tenant: $this->account))
            ->assertOk()
            ->assertSee('Sites behind the newest version')
            ->assertSee('Sites that went quiet');
    }

    /**
     * A silent site is usually a broken wp-cron, not a departure, and the
     * two are indistinguishable from the server. Saying so on the page is
     * the difference between a margin of error and a phantom churn number.
     */
    public function test_the_reports_page_says_what_silence_actually_means(): void
    {
        $this->seedSites(1);

        $this->get(ProjectResource::getUrl('reports', ['record' => $this->project], tenant: $this->account))
            ->assertOk()
            ->assertSee('wp-cron', escape: false)
            ->assertSee('not as churn', escape: false);
    }

    /**
     * Relation managers are lazily-loaded Livewire components, so they are
     * absent from the page's initial HTML and have to be driven directly.
     */
    public function test_the_metadata_whitelist_can_be_edited(): void
    {
        Filament::setTenant($this->account);

        $field = ProjectMetaField::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'key' => 'pro_version',
            'datatype' => ProjectMetaField::TYPE_STRING,
        ]);

        Livewire::test(MetaFieldsRelationManager::class, [
            'ownerRecord' => $this->project,
            'pageClass' => EditProject::class,
        ])->assertCanSeeTableRecords([$field]);
    }

    public function test_the_reason_editor_is_seeded_with_the_sdk_defaults(): void
    {
        Filament::setTenant($this->account);

        $reasons = $this->project->deactivationReasons()->get();

        $this->assertCount(7, $reasons, 'the SDK ships seven reasons');

        Livewire::test(DeactivationReasonsRelationManager::class, [
            'ownerRecord' => $this->project,
            'pageClass' => EditProject::class,
        ])
            ->assertCanSeeTableRecords($reasons)
            ->assertSee('Found a better plugin');
    }

    /**
     * The plugin inventory is aggregate-view only. It is the most sensitive
     * thing collected -- a fingerprint of somebody's business -- and the
     * one field that must never leave in a file.
     */
    public function test_no_export_can_carry_the_plugin_inventory(): void
    {
        foreach ([SiteResource::class, EndUserResource::class, DeactivationResource::class] as $resource) {
            $source = file_get_contents((new \ReflectionClass($resource))->getFileName());

            preg_match('/ExportTableAction::for\(\[(.*?)\]\s*,/s', $source, $columns);

            $this->assertNotEmpty($columns, $resource.' should export something');

            foreach (['plugins', 'site_plugins', 'inventory', 'slug'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $columns[1],
                    $resource." must not export the plugin inventory [{$forbidden}]",
                );
            }
        }
    }

    public function test_exports_are_reachable_from_the_analytics_tables(): void
    {
        $this->seedSites(2);

        foreach ([SiteResource::class, EndUserResource::class, DeactivationResource::class] as $resource) {
            $this->get($resource::getUrl('index', tenant: $this->account))
                ->assertOk()
                ->assertSee('Export CSV');
        }
    }
}
