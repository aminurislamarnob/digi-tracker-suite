<?php

namespace Tests\Feature\Ingest;

use App\Models\Project;
use App\Models\RawPayload;
use App\Models\TrackingSkip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingSkippedTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_a_refusal_is_counted(): void
    {
        $this->post('/tracking-skipped', [
            'hash' => $this->project->hash,
            'previously_skipped' => '',
        ])->assertOk()->assertJson(['success' => true]);

        $skip = TrackingSkip::acrossAccounts()->sole();

        $this->assertSame($this->project->account_id, $skip->account_id);
        $this->assertFalse($skip->previously_skipped);
    }

    /**
     * Same trap as everywhere else: http_build_query turns false into an
     * empty string, so a `boolean` rule would reject a first-time refusal.
     */
    public function test_the_repeat_flag_survives_form_encoding(): void
    {
        $this->post('/tracking-skipped', [
            'hash' => $this->project->hash,
            'previously_skipped' => '1',
        ])->assertOk();

        $this->assertTrue(TrackingSkip::acrossAccounts()->sole()->previously_skipped);
    }

    /**
     * The SDK deliberately sends no URL, email or environment with a
     * refusal. Recording any of it would take by inference exactly what
     * the person just declined to give.
     */
    public function test_a_refusal_creates_no_site_and_no_end_user(): void
    {
        $this->post('/tracking-skipped', [
            'hash' => $this->project->hash,
            'url' => 'https://example.com',
            'admin_email' => 'owner@example.com',
        ])->assertOk();

        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('end_users', 0);
    }

    /**
     * This is the one route that fires without consent, which is defensible
     * only because what it carries identifies nobody. An IP is personal
     * data and the user agent carries md5(home_url), a stable site marker:
     * recording either would turn a refusal into a record of who refused.
     */
    public function test_a_refusal_records_nothing_that_identifies_anyone(): void
    {
        $this->post('/tracking-skipped', [
            'hash' => $this->project->hash,
            'previously_skipped' => '',
        ], ['HTTP_USER_AGENT' => 'DigiTracker/'.md5('https://example.com').';'])->assertOk();

        $payload = RawPayload::acrossAccounts()->sole();

        $this->assertNull($payload->ip);
        $this->assertNull($payload->user_agent);
        $this->assertSame(['previously_skipped' => false], $payload->payload);

        // And nothing on the counted row either.
        $this->assertArrayNotHasKey('ip', TrackingSkip::acrossAccounts()->sole()->getAttributes());
    }

    public function test_an_unknown_hash_is_rejected(): void
    {
        $this->post('/tracking-skipped', [
            'hash' => '00000000-0000-4000-8000-000000000000',
        ])->assertNotFound();

        $this->assertDatabaseCount('tracking_skips', 0);
    }
}
