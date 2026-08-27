<?php

namespace Tests\Feature\Panel;

use App\Filament\Resources\Projects\Pages\ProjectRepository;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\RepositoryDownloadsChart;
use App\Models\Account;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesWordPressOrg;
use Tests\TestCase;

/**
 * Client-side navigation inside the panel.
 *
 * Two things are worth pinning, and neither is visible by reading the
 * markup. The first is that panel links carry wire:navigate at all -- the
 * setting lives in a provider, so a merge that drops one line would remove
 * the whole behaviour with nothing failing anywhere.
 *
 * The second is the exceptions, which are the safety half. Logging in and
 * out is where the session identity changes, and swapping a body into a
 * document rendered for somebody else invites a stale CSRF token or a
 * tenant menu belonging to the previous user. A link that quietly gains
 * wire:navigate is exactly the kind of regression nobody notices until the
 * second person signs in on a shared machine.
 */
class SpaNavigationTest extends TestCase
{
    use FakesWordPressOrg, RefreshDatabase;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDownloadSummary();

        $this->account = Account::factory()->create(['slug' => 'pluginizelab']);

        Project::factory()->for($this->account)->create([
            'slug' => 'metadata-viewer',
            'wporg_slug' => 'metadata-viewer',
        ]);

        $user = User::factory()->create();
        $user->accounts()->attach($this->account, ['role' => 'owner']);

        $this->actingAs($user);
        Filament::setTenant($this->account);
        CurrentAccount::set($this->account);
    }

    public function test_spa_mode_is_on(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasSpaMode());
    }

    /**
     * Off deliberately. Prefetching fetches on hover, and the repository
     * dashboard calls wordpress.org as it renders -- so hovering a tab would
     * put real requests on a public API for a page nobody opened.
     */
    public function test_prefetching_is_off(): void
    {
        $this->assertFalse(Filament::getPanel('admin')->hasSpaPrefetching());
    }

    public function test_navigation_links_are_client_side(): void
    {
        $html = $this->get(ProjectResource::getUrl('index', tenant: $this->account))->assertOk()->getContent();

        $this->assertStringContainsString('wire:navigate', $html);
    }

    /**
     * The one that matters. Logging out has to tear the page down rather
     * than swap it, or what is left standing was rendered for the person
     * who just left.
     */
    public function test_the_auth_urls_are_excluded(): void
    {
        $exceptions = Filament::getPanel('admin')->getSpaUrlExceptions();

        foreach (['login', 'logout', 'register'] as $route) {
            $this->assertContains(url("/admin/{$route}"), $exceptions);
        }

        $this->assertContains(url('/admin/password-reset/*'), $exceptions);
    }

    /**
     * Asserted through Filament's own resolver rather than by reading the
     * array, because that is the method the views call -- a URL in the list
     * that this still answers `true` for would be an exception in name only.
     */
    public function test_the_resolver_agrees(): void
    {
        $this->get(ProjectResource::getUrl('index', tenant: $this->account))->assertOk();

        $this->assertFalse(FilamentView::hasSpaMode(url('/admin/logout')));
        $this->assertFalse(FilamentView::hasSpaMode(url('/admin/password-reset/request')));

        // A page inside the panel still navigates client-side.
        $this->assertTrue(FilamentView::hasSpaMode(
            url('/admin/pluginizelab/projects'),
        ));
    }

    /**
     * The one real hazard in turning this on.
     *
     * wire:navigate replaces the body and leaves the head alone, so a
     * `<script>` that ran on first load never runs again -- the classic
     * symptom being a chart that draws once and is a blank rectangle on
     * every visit after.
     *
     * Filament's charts defend against that with x-load, which fetches the
     * component's JavaScript on demand and re-runs on livewire:navigated
     * rather than on DOMContentLoaded.
     *
     * Asserted on the widget's own render rather than the page's, because
     * header widgets are lazy: the page ships a placeholder and the chart
     * markup arrives in a later Livewire request. Looking for it in the page
     * HTML finds nothing and proves nothing.
     */
    public function test_the_chart_widget_loads_its_script_on_demand(): void
    {
        $html = Livewire::test(RepositoryDownloadsChart::class, [
            'record' => Project::query()->where('slug', 'metadata-viewer')->firstOrFail(),
        ])->assertOk()->html();

        // x-load-src rather than a bare <script>: the difference between a
        // chart that survives client-side navigation and one that does not.
        $this->assertStringContainsString('x-load', $html);
        $this->assertStringContainsString('chart.js', $html);
    }

    /** The page itself still renders server-side, lazy widgets aside. */
    public function test_the_repository_page_still_renders(): void
    {
        $this->get(ProjectRepository::getUrl(['record' => 'metadata-viewer']))
            ->assertOk()
            ->assertSee('Conversion');
    }
}
