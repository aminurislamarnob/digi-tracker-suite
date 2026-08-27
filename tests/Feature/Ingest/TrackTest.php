<?php

namespace Tests\Feature\Ingest;

use App\Models\EndUser;
use App\Models\Project;
use App\Models\RawPayload;
use App\Models\Site;
use App\Models\SiteReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TrackTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    /**
     * A realistic heartbeat, posted the way the SDK actually posts it.
     *
     * Note this is form-encoded, not JSON, and nested values are flattened
     * by http_build_query before they leave the site.
     */
    protected function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'hash' => $this->project->hash,
            'url' => 'https://example.com',
            'site' => 'Example Site',
            'admin_email' => 'owner@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'project_version' => '2.2.4',
            'server' => [
                'software' => 'nginx/1.24.0',
                'php_version' => '8.2.4',
                'mysql_version' => '8.0.36',
            ],
            'wp' => [
                'version' => '6.8',
                'locale' => 'en_US',
                'memory_limit' => '256M',
                'debug_mode' => 'No',
                'multisite' => 'No',
                'theme_slug' => 'twentytwentyfive',
                'theme_name' => 'Twenty Twenty-Five',
                'theme_version' => '1.2',
            ],
            'users' => ['total' => 5, 'administrator' => 2, 'editor' => 3],
            'active_plugins' => 14,
            'inactive_plugins' => 3,
            'is_local' => '',
            'tracking_skipped' => '',
            'client' => '2.0.4',
        ], $overrides);
    }

    protected function track(array $payload): TestResponse
    {
        return $this->call(
            'POST',
            '/track',
            $payload,
            server: [
                'HTTP_USER_AGENT' => 'Appsero/'.md5('https://example.com').';',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
        );
    }

    public function test_a_heartbeat_creates_a_site_a_report_and_an_end_user(): void
    {
        $this->track($this->payload())->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseCount('raw_payloads', 1);

        $site = Site::acrossAccounts()->sole();

        $this->assertSame($this->project->id, $site->project_id);
        $this->assertSame($this->project->account_id, $site->account_id);
        $this->assertSame('example.com', $site->canonical_url);
        $this->assertSame(Site::STATUS_ACTIVE, $site->status);
        $this->assertSame('2.2.4', $site->current_version);
        $this->assertSame('8.2.4', $site->php_version);
        $this->assertSame(md5('https://example.com'), $site->ua_fingerprint);

        $report = SiteReport::acrossAccounts()->sole();

        $this->assertSame('nginx/1.24.0', $report->server_software);
        $this->assertSame('8.0.36', $report->mysql_version);
        $this->assertSame('en_US', $report->locale);
        $this->assertSame(5, $report->users_total);
        // Form encoding makes every count a string; they must come back as ints.
        $this->assertSame(2, $report->users_by_role['administrator']);
        $this->assertSame(3, $report->users_by_role['editor']);
        $this->assertArrayNotHasKey('total', $report->users_by_role);
        $this->assertSame(14, $report->active_plugins);
        $this->assertSame('2.0.4', $report->client_version);

        $endUser = EndUser::acrossAccounts()->sole();

        $this->assertSame('owner@example.com', $endUser->email);
        $this->assertSame('Ada', $endUser->first_name);
        $this->assertSame($endUser->id, $site->end_user_id);
    }

    /**
     * The single most likely regression in this codebase.
     *
     * wp_remote_post runs the body through http_build_query, so PHP's true
     * becomes "1" and false becomes an EMPTY STRING. Validating these as
     * booleans would reject every payload from a non-local site.
     */
    public function test_empty_string_booleans_are_false_and_one_is_true(): void
    {
        $this->track($this->payload(['is_local' => '']))->assertOk();

        $this->assertFalse(Site::acrossAccounts()->sole()->is_local);

        Site::acrossAccounts()->delete();
        RawPayload::acrossAccounts()->delete();

        $this->track($this->payload(['url' => 'https://local.test', 'is_local' => '1']))->assertOk();

        $this->assertTrue(Site::acrossAccounts()->sole()->is_local);
    }

    /**
     * The SDK reports wp[] flags as the literal strings 'Yes' and 'No'.
     * filter_var() alone reads 'No' as truthy, which would invert them.
     */
    public function test_wordpress_yes_no_flags_are_interpreted_correctly(): void
    {
        $this->track($this->payload(['wp' => ['multisite' => 'Yes', 'debug_mode' => 'No']]))->assertOk();

        $report = SiteReport::acrossAccounts()->sole();

        $this->assertTrue($report->multisite);
        $this->assertFalse($report->debug_mode);
    }

    public function test_the_same_site_reporting_twice_is_one_site_and_two_reports(): void
    {
        $this->track($this->payload())->assertOk();
        $this->track($this->payload(['project_version' => '2.3.0']))->assertOk();

        $this->assertSame(1, Site::acrossAccounts()->count());
        $this->assertSame(2, SiteReport::acrossAccounts()->count());
        $this->assertSame('2.3.0', Site::acrossAccounts()->sole()->current_version);
    }

    /**
     * Without canonicalisation each of these reads as a separate install
     * and every headline count inflates.
     */
    public function test_url_variants_resolve_to_one_site(): void
    {
        foreach ([
            'https://example.com',
            'http://www.example.com/',
            'https://EXAMPLE.com:443',
            'https://www.example.com',
        ] as $url) {
            $this->track($this->payload(['url' => $url]))->assertOk();
        }

        $this->assertSame(1, Site::acrossAccounts()->count());

        // One report, not four: see the duplicate-suppression test below.
        $this->assertSame(1, SiteReport::acrossAccounts()->count());
    }

    /**
     * A site reports weekly. Anything arriving again within hours with an
     * unchanged environment is a retry or a misconfigured cron, and would
     * otherwise weight that one site four times in every distribution.
     */
    public function test_an_identical_repeat_within_the_window_does_not_add_a_report(): void
    {
        $this->track($this->payload())->assertOk();
        $this->track($this->payload())->assertOk();

        $this->assertSame(1, SiteReport::acrossAccounts()->count());

        // But the site is still seen, and a genuine change still lands.
        $this->assertSame(2, RawPayload::acrossAccounts()->count());

        $this->travel(7)->days();
        $this->track($this->payload())->assertOk();

        $this->assertSame(2, SiteReport::acrossAccounts()->count());
    }

    public function test_an_unknown_hash_is_rejected(): void
    {
        $this->track($this->payload(['hash' => (string) Str::uuid()]))->assertNotFound();

        $this->assertDatabaseCount('raw_payloads', 0);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_an_inactive_project_is_rejected(): void
    {
        $this->project->update(['is_active' => false]);

        $this->track($this->payload())->assertNotFound();
    }

    public function test_a_missing_required_field_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['project_version']);

        $this->track($payload)->assertStatus(422);
    }

    /**
     * The payload's own ip_address is fetched by the customer's server from
     * icanhazip.com -- a third-party call our SDK fork removes. What we
     * observed is always more trustworthy than what we were told.
     */
    public function test_the_observed_ip_wins_over_the_reported_one(): void
    {
        $this->track($this->payload(['ip_address' => '203.0.113.99']))->assertOk();

        $this->assertNotSame('203.0.113.99', Site::acrossAccounts()->sole()->ip);
    }
}
