<?php

namespace Tests\Feature\Ingest;

use App\Models\Project;
use App\Models\Site;
use App\Models\SitePlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

class SitePluginsTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_the_inventory_is_stored_per_site(): void
    {
        $this->track($this->project, ['plugins' => [
            'woocommerce' => ['name' => 'WooCommerce', 'version' => '9.3.1'],
            'akismet' => ['name' => 'Akismet Anti-spam', 'version' => '5.3'],
        ]])->assertOk();

        $this->assertSame(2, SitePlugin::acrossAccounts()->count());

        $woo = SitePlugin::acrossAccounts()->where('slug', 'woocommerce')->sole();

        $this->assertSame('WooCommerce', $woo->name);
        $this->assertSame('9.3.1', $woo->version);
        $this->assertSame(Site::acrossAccounts()->sole()->id, $woo->site_id);
    }

    /**
     * Current state, not history: the payload carries active plugins only,
     * so anything missing from it has been deactivated or removed.
     */
    public function test_plugins_absent_from_a_later_payload_are_dropped(): void
    {
        $this->track($this->project, ['plugins' => [
            'woocommerce' => ['name' => 'WooCommerce', 'version' => '9.3.1'],
            'akismet' => ['name' => 'Akismet Anti-spam', 'version' => '5.3'],
        ]])->assertOk();

        $this->travel(7)->days();

        $this->track($this->project, ['plugins' => [
            'woocommerce' => ['name' => 'WooCommerce', 'version' => '9.4.0'],
        ]])->assertOk();

        $this->assertSame(['woocommerce'], SitePlugin::acrossAccounts()->pluck('slug')->all());
        $this->assertSame('9.4.0', SitePlugin::acrossAccounts()->sole()->version);
    }

    /**
     * The payload is untrusted and unauthenticated. A claim of ten thousand
     * plugins is not a WordPress site.
     */
    public function test_an_implausible_inventory_is_capped(): void
    {
        $plugins = [];

        for ($i = 0; $i < 600; $i++) {
            $plugins["plugin-{$i}"] = ['name' => "Plugin {$i}", 'version' => '1.0'];
        }

        $this->track($this->project, ['plugins' => $plugins])->assertOk();

        $this->assertSame(500, SitePlugin::acrossAccounts()->count());
    }

    public function test_a_payload_without_plugin_data_leaves_the_inventory_alone(): void
    {
        $this->track($this->project, ['plugins' => [
            'woocommerce' => ['name' => 'WooCommerce', 'version' => '9.3.1'],
        ]])->assertOk();

        $this->travel(7)->days();

        // add_plugin_data() switched off: the key is absent, which is not
        // the same claim as "this site now runs no plugins".
        $this->track($this->project, ['project_version' => '2.3.0'])->assertOk();

        $this->assertSame(1, SitePlugin::acrossAccounts()->count());
    }
}
