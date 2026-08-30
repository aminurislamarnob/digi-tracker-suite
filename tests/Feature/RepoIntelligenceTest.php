<?php

namespace Tests\Feature;

use App\Jobs\RefreshRepoStats;
use App\Models\Account;
use App\Models\Project;
use App\Models\RepoDownload;
use App\Models\RepoKeyword;
use App\Models\RepoRanking;
use App\Models\RepoRelease;
use App\Models\RepoSnapshot;
use App\Services\RepoDownloads;
use App\Services\RepoSnapshotter;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Collecting the public half of the picture.
 *
 * Every response here is faked, deliberately. Tests that hit
 * wordpress.org would be testing their uptime rather than our parsing, and
 * would put a test suite's worth of traffic on a public API we are a guest
 * on. The fixtures are shaped like the real payloads -- including the parts
 * that are awkward: an error delivered with a 200, dates in a format no
 * strict parser accepts, and empty strings where a field is simply unset.
 */
class RepoIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Linking a project queues its first capture -- see
         * Project::refreshRepositoryOnLink. Faked before the project is
         * created, or the sync queue runs a real fetch in setUp.
         */
        Queue::fake();

        $this->project = Project::factory()->for(Account::factory())->create([
            'slug' => 'metadata-viewer',
            'wporg_slug' => 'metadata-viewer',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function fakeRepository(array $overrides = []): void
    {
        $info = array_merge([
            'name' => 'Metadata Viewer',
            'slug' => 'metadata-viewer',
            'version' => '2.2.4',
            'active_installs' => 500,
            'downloaded' => 3044,
            'rating' => 100,
            'num_ratings' => 2,
            'ratings' => ['5' => 2, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
            'support_threads' => 4,
            'support_threads_resolved' => 3,
            'requires' => '6.0.0',
            'requires_php' => '7.4',
            'tested' => '6.9.7',
            // The real format, which strtotime handles and a strict parser
            // does not.
            'last_updated' => '2026-05-13 8:39am GMT',
        ], $overrides);

        Http::fake([
            'api.wordpress.org/plugins/info/1.2/*action=plugin_information*' => Http::response($info),
            /*
             * Before the daily-series pattern, because historical_summary
             * is a query parameter on the same path -- the series stub
             * would otherwise swallow it and hand back a list of dates
             * where four totals were expected.
             */
            'api.wordpress.org/stats/plugin/1.0/downloads.php*historical_summary*' => Http::response([
                'today' => '10', 'yesterday' => '9', 'last_week' => '63', 'all_time' => '4468',
            ]),
            'api.wordpress.org/stats/plugin/1.0/downloads.php*' => Http::response([
                '2026-08-24' => 11,
                '2026-08-25' => 8,
                '2026-08-26' => 9,
            ]),
            'api.wordpress.org/stats/plugin/1.0/*' => Http::response(['2.2' => 71.5, 'other' => 28.5]),
            'plugins.svn.wordpress.org/*' => Http::response($this->svnTagsXml()),
        ]);
    }

    protected function svnTagsXml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <D:multistatus xmlns:D="DAV:">
        <D:response>
          <D:href>/metadata-viewer/tags/</D:href>
          <D:propstat><D:prop><D:creationdate>2026-05-13T08:39:25.000000Z</D:creationdate></D:prop>
          <D:status>HTTP/1.1 200 OK</D:status></D:propstat>
        </D:response>
        <D:response>
          <D:href>/metadata-viewer/tags/2.2.3/</D:href>
          <D:propstat><D:prop><D:creationdate>2026-01-10T09:00:00.000000Z</D:creationdate></D:prop>
          <D:status>HTTP/1.1 200 OK</D:status></D:propstat>
        </D:response>
        <D:response>
          <D:href>/metadata-viewer/tags/2.2.4/</D:href>
          <D:propstat><D:prop><D:creationdate>2026-05-13T08:39:00.000000Z</D:creationdate></D:prop>
          <D:status>HTTP/1.1 200 OK</D:status></D:propstat>
        </D:response>
        </D:multistatus>
        XML;
    }

    protected function capture(): array
    {
        return app(RepoSnapshotter::class)->capture($this->project->refresh());
    }

    public function test_a_capture_records_the_public_record(): void
    {
        $this->fakeRepository();

        $this->capture();

        $snapshot = RepoSnapshot::acrossAccounts()->firstOrFail();

        $this->assertSame(500, $snapshot->active_installs);
        $this->assertSame('2.2.4', $snapshot->version);
        $this->assertSame(100, $snapshot->rating);
        $this->assertSame(2, $snapshot->num_ratings);
        $this->assertSame(['2.2' => 71.5, 'other' => 28.5], $snapshot->version_distribution);
        $this->assertSame('2026-05-13', $snapshot->last_updated_at->toDateString());

        // Tenancy is stamped from the project, never inferred.
        $this->assertSame($this->project->account_id, $snapshot->account_id);
    }

    public function test_download_history_is_backfilled(): void
    {
        $this->fakeRepository();

        $this->capture();

        $this->assertSame(3, RepoDownload::acrossAccounts()->count());
        $this->assertSame(11, RepoDownload::acrossAccounts()->where('date', '2026-08-24')->value('downloads'));
    }

    /**
     * The repository revises recent days as mirrors report in, so a second
     * fetch must correct the tail rather than duplicate it or refuse it.
     */
    public function test_a_revised_download_count_overwrites_rather_than_duplicates(): void
    {
        /*
         * A stub that answers from a variable, rather than a sequence.
         *
         * A sequence would have to know exactly how many times the endpoint
         * is called per capture, and it is called more than once: the
         * window excludes the day in progress, so today is fetched
         * separately, and the summary is a third call on the same path.
         * Pinning a test to that count makes it fail the next time the
         * service asks one more question, which is noise rather than
         * signal. A variable pins what matters -- what the second fetch
         * says -- and nothing else.
         */
        $revised = false;

        Http::fake([
            'api.wordpress.org/plugins/info/1.2/*action=plugin_information*' => Http::response([
                'slug' => 'metadata-viewer', 'version' => '2.2.4',
            ]),
            'api.wordpress.org/stats/plugin/1.0/downloads.php*historical_summary*' => Http::response([
                'today' => '3', 'yesterday' => '9', 'last_week' => '40', 'all_time' => '4468',
            ]),
            'api.wordpress.org/stats/plugin/1.0/downloads.php*' => function ($request) use (&$revised) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                // limit=1 is wordpress.org's today-only response, and this
                // plugin has no downloads today in either fetch.
                if (($query['limit'] ?? null) === '1') {
                    return Http::response([]);
                }

                return Http::response($revised
                    ? ['2026-08-26' => 17]
                    : ['2026-08-24' => 11, '2026-08-25' => 8, '2026-08-26' => 9]);
            },
            'api.wordpress.org/stats/plugin/1.0/*' => Http::response([]),
            'plugins.svn.wordpress.org/*' => Http::response($this->svnTagsXml()),
        ]);

        $this->capture();

        $revised = true;

        $this->capture();

        $this->assertSame(3, RepoDownload::acrossAccounts()->count());
        $this->assertSame(17, RepoDownload::acrossAccounts()->where('date', '2026-08-26')->value('downloads'));
    }

    public function test_release_dates_come_from_subversion_with_the_tags_directory_excluded(): void
    {
        $this->fakeRepository();

        $this->capture();

        $releases = RepoRelease::acrossAccounts()->pluck('released_on', 'version');

        $this->assertSame('2026-01-10', $releases['2.2.3']->toDateString());
        $this->assertSame('2026-05-13', $releases['2.2.4']->toDateString());

        // The tags directory itself carries a creationdate and is not a
        // release; letting it through would invent a version called "tags".
        $this->assertArrayNotHasKey('tags', $releases->all());
    }

    /**
     * An observed date is only ever "no later than this". The moment
     * Subversion offers the real one it must win.
     */
    public function test_an_exact_date_replaces_a_previously_observed_one(): void
    {
        RepoRelease::acrossAccounts()->create([
            'account_id' => $this->project->account_id,
            'project_id' => $this->project->id,
            'version' => '2.2.4',
            'released_on' => '2026-08-01',
            'source' => RepoRelease::FROM_OBSERVATION,
        ]);

        $this->fakeRepository();
        $this->capture();

        $release = RepoRelease::acrossAccounts()->where('version', '2.2.4')->firstOrFail();

        $this->assertSame(RepoRelease::FROM_SVN, $release->source);
        $this->assertSame('2026-05-13', $release->released_on->toDateString());
    }

    /**
     * A missing slug answers 200 with an error body rather than a 404, so
     * checking the status code alone would store a snapshot full of nulls
     * and call it a successful capture.
     */
    public function test_an_error_delivered_with_a_200_is_not_treated_as_data(): void
    {
        Http::fake([
            'api.wordpress.org/*' => Http::response(['error' => 'Plugin not found.']),
            'plugins.svn.wordpress.org/*' => Http::response('', 404),
        ]);

        $result = $this->capture();

        $this->assertNull($result['snapshot']);
        $this->assertSame(0, RepoSnapshot::acrossAccounts()->count());
    }

    /** A project with no public listing is not an error, it just has no public half. */
    public function test_a_project_without_a_repository_slug_is_skipped_silently(): void
    {
        Http::fake();

        $this->project->update(['wporg_slug' => null]);

        $result = $this->capture();

        $this->assertNull($result['snapshot']);
        Http::assertNothingSent();
    }

    /** A repository outage must cost one day, not fail the scheduled run. */
    public function test_an_unreachable_repository_fails_soft(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $result = $this->capture();

        $this->assertNull($result['snapshot']);
        $this->assertSame(0, $result['downloads']);
    }

    public function test_capturing_twice_in_a_day_updates_one_row(): void
    {
        // Sequenced for the same reason as above: a later Http::fake() adds
        // stubs behind the existing ones instead of replacing them.
        Http::fake([
            'api.wordpress.org/plugins/info/1.2/*action=plugin_information*' => Http::sequence()
                ->push(['slug' => 'metadata-viewer', 'version' => '2.2.4', 'active_installs' => 500])
                ->push(['slug' => 'metadata-viewer', 'version' => '2.2.4', 'active_installs' => 600]),
            'api.wordpress.org/stats/plugin/1.0/downloads.php*' => Http::response([]),
            'api.wordpress.org/stats/plugin/1.0/*' => Http::response([]),
            'plugins.svn.wordpress.org/*' => Http::response($this->svnTagsXml()),
        ]);

        $this->capture();
        $this->capture();

        $this->assertSame(1, RepoSnapshot::acrossAccounts()->count());
        $this->assertSame(600, RepoSnapshot::acrossAccounts()->first()->active_installs);
    }

    public function test_the_command_captures_every_linked_project(): void
    {
        $this->fakeRepository();

        $this->artisan('telemetry:fetch-repo-stats')
            ->expectsOutputToContain('1 project(s) captured.')
            ->assertSuccessful();
    }

    /* ---------------------------------------------------------------- */
    /* Keyword rankings */
    /* ---------------------------------------------------------------- */

    protected function fakeSearch(array $slugs): void
    {
        Http::fake([
            'api.wordpress.org/plugins/info/1.2/*action=query_plugins*' => Http::response([
                'info' => ['results' => 514],
                'plugins' => array_map(fn ($slug) => ['slug' => $slug], $slugs),
            ]),
        ]);
    }

    public function test_a_ranked_position_is_recorded(): void
    {
        $this->fakeSearch(['other-plugin', 'metadata-viewer', 'another']);

        RepoKeyword::acrossAccounts()->create([
            'account_id' => $this->project->account_id,
            'project_id' => $this->project->id,
            'keyword' => 'metadata viewer',
        ]);

        $this->artisan('telemetry:track-keywords')->assertSuccessful();

        $ranking = RepoRanking::acrossAccounts()->firstOrFail();

        $this->assertSame(2, $ranking->position);
        $this->assertSame(514, $ranking->total_results);
        $this->assertTrue($ranking->isRanked());
    }

    /**
     * The distinction the whole table is built around. Outside the window
     * is the absence of a rank, not a bad one, and storing 999 for it would
     * quietly drag every average toward a value that never happened.
     */
    public function test_not_being_found_is_null_rather_than_a_large_number(): void
    {
        $this->fakeSearch(['someone-else', 'and-another']);

        RepoKeyword::acrossAccounts()->create([
            'account_id' => $this->project->account_id,
            'project_id' => $this->project->id,
            'keyword' => 'unrelated term',
        ]);

        $this->artisan('telemetry:track-keywords')->assertSuccessful();

        $ranking = RepoRanking::acrossAccounts()->firstOrFail();

        $this->assertNull($ranking->position);
        $this->assertFalse($ranking->isRanked());
        $this->assertGreaterThan(0, $ranking->searched_depth);
    }

    public function test_keywords_are_normalised_so_one_term_is_not_tracked_twice(): void
    {
        $keyword = RepoKeyword::acrossAccounts()->create([
            'account_id' => $this->project->account_id,
            'project_id' => $this->project->id,
            'keyword' => '  Metadata   VIEWER ',
        ]);

        $this->assertSame('metadata viewer', $keyword->fresh()->keyword);
    }

    /* ---------------------------------------------------------------- */
    /* Tenancy */
    /* ---------------------------------------------------------------- */

    /**
     * Six new tables, all carrying account_id. Isolation is asserted here
     * rather than assumed to follow from the trait being present.
     */
    public function test_one_account_cannot_see_anothers_repository_data(): void
    {
        $this->fakeRepository();
        $this->capture();

        $stranger = Project::factory()->for(Account::factory())->create([
            'wporg_slug' => 'someone-elses-plugin',
        ]);

        CurrentAccount::set($stranger->account);

        $this->assertSame(0, RepoSnapshot::count());
        $this->assertSame(0, RepoDownload::count());
        $this->assertSame(0, RepoRelease::count());
    }

    /* ---------------------------------------------------------------- */
    /* Refreshing on demand */
    /* ---------------------------------------------------------------- */

    /**
     * The queued refresh writes what the nightly command writes.
     *
     * It runs with no account set, as a worker does, so this doubles as the
     * assertion that it reaches past the tenant scope -- a job that quietly
     * captured nothing would look identical to one that never ran.
     */
    public function test_the_queued_refresh_captures_the_public_record(): void
    {
        $this->fakeRepository();

        CurrentAccount::clear();

        (new RefreshRepoStats($this->project->id))->handle(app(RepoSnapshotter::class));

        CurrentAccount::set($this->project->account);

        $this->assertSame(1, RepoSnapshot::count());
        $this->assertSame(3, RepoDownload::count());
    }

    /**
     * The live summary the headline reads is invalidated too.
     *
     * Without this the four largest figures on the page would keep serving
     * a fifteen-minute-old cache while everything around them updated,
     * which reads as the refresh having done nothing.
     */
    public function test_the_queued_refresh_drops_the_cached_download_summary(): void
    {
        $this->fakeRepository();

        Cache::put(RepoDownloads::cacheKey('metadata-viewer'), ['all_time' => 1], 900);

        (new RefreshRepoStats($this->project->id))->handle(app(RepoSnapshotter::class));

        $this->assertFalse(Cache::has(RepoDownloads::cacheKey('metadata-viewer')));
    }

    /**
     * A slug cleared between the button press and the worker picking the
     * job up must not become a request for an empty slug.
     */
    public function test_the_queued_refresh_does_nothing_for_a_project_without_a_slug(): void
    {
        Http::fake();

        $this->project->update(['wporg_slug' => null]);

        (new RefreshRepoStats($this->project->id))->handle(app(RepoSnapshotter::class));

        Http::assertNothingSent();
        $this->assertSame(0, RepoSnapshot::count());
    }

    /* ---------------------------------------------------------------- */
    /* Linking a project */
    /* ---------------------------------------------------------------- */

    /**
     * A project linked mid-afternoon must not wait until 03:00.
     *
     * This is the case that produced an empty chart under populated cards:
     * the four totals at the top of the repository page come live from
     * wordpress.org, the chart reads a table nothing had written to yet,
     * and the difference reads as a broken chart rather than a project
     * added an hour ago.
     */
    public function test_linking_a_project_queues_its_first_capture(): void
    {
        Queue::fake();

        $project = Project::factory()->for(Account::factory())->create([
            'wporg_slug' => 'newly-linked',
        ]);

        Queue::assertPushed(
            RefreshRepoStats::class,
            fn (RefreshRepoStats $job) => $job->projectId === $project->id,
        );
    }

    /** Setting the slug on a project that had none is the same event. */
    public function test_adding_a_slug_later_queues_the_capture_too(): void
    {
        $project = Project::factory()->for(Account::factory())->create(['wporg_slug' => null]);

        Queue::fake();

        $project->update(['wporg_slug' => 'linked-later']);

        Queue::assertPushed(RefreshRepoStats::class);
    }

    /**
     * Every other save must be silent.
     *
     * Renaming a project is not news from wordpress.org, and a capture is
     * four requests against a public API we are a guest on. A hook that
     * fired on any save would turn a typo fix into a fetch.
     */
    public function test_an_unrelated_edit_queues_nothing(): void
    {
        $project = Project::factory()->for(Account::factory())->create(['wporg_slug' => 'settled']);

        Queue::fake();

        $project->update(['name' => 'Renamed']);

        Queue::assertNothingPushed();
    }

    /** Unlinking leaves nothing to fetch. */
    public function test_clearing_the_slug_queues_nothing(): void
    {
        $project = Project::factory()->for(Account::factory())->create(['wporg_slug' => 'settled']);

        Queue::fake();

        $project->update(['wporg_slug' => null]);

        Queue::assertNothingPushed();
    }

    /** Demo projects are excluded from the nightly run, and from this. */
    public function test_a_demo_project_queues_nothing(): void
    {
        Queue::fake();

        Project::factory()->for(Account::factory())->create([
            'wporg_slug' => 'demo-plugin',
            'is_demo' => true,
        ]);

        Queue::assertNothingPushed();
    }

    /**
     * A slug corrected twice in a minute is one fetch, not two.
     *
     * The link shares the button's cooldown key, so a typo fixed straight
     * away -- which is what a second change inside a minute looks like --
     * does not become a second full capture.
     */
    public function test_a_slug_corrected_immediately_does_not_queue_twice(): void
    {
        Queue::fake();

        $project = Project::factory()->for(Account::factory())->create([
            'wporg_slug' => 'typo-slug',
        ]);

        $project->update(['wporg_slug' => 'corrected-slug']);

        Queue::assertPushed(RefreshRepoStats::class, 1);
    }
}
