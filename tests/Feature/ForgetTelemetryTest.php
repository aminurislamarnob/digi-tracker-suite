<?php

namespace Tests\Feature;

use App\Models\Deactivation;
use App\Models\EndUser;
use App\Models\Project;
use App\Models\RawPayload;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * "Indefinite retention, deletion on request" is only a policy if the
 * deletion actually happens. These tests are the proof.
 */
class ForgetTelemetryTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    protected function seedOneSite(string $host = 'example.com', string $email = 'owner@example.com'): void
    {
        $this->track($this->project, [
            'url' => "https://{$host}",
            'admin_email' => $email,
            'plugins' => ['woocommerce' => ['name' => 'WooCommerce', 'version' => '9.4.2']],
        ])->assertOk();

        $this->travel(7)->days();

        $this->deactivate($this->project, [
            'url' => "https://{$host}",
            'admin_email' => $email,
            'reason_id' => 'other',
            'reason_info' => 'Please delete my data.',
        ])->assertOk();
    }

    public function test_it_erases_everything_for_an_email(): void
    {
        $this->seedOneSite();

        $this->artisan('telemetry:forget', ['--email' => 'owner@example.com', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, EndUser::acrossAccounts()->count());
        $this->assertSame(0, Site::acrossAccounts()->count());
        $this->assertSame(0, SiteReport::acrossAccounts()->count());
        $this->assertSame(0, SitePlugin::acrossAccounts()->count());
        $this->assertSame(0, Deactivation::acrossAccounts()->count());
    }

    /**
     * The reason this command exists rather than a tinker snippet. Nothing
     * cascades to raw_payloads -- it is kept outside the foreign-key graph
     * on purpose -- so a hand-rolled deletion leaves the original heartbeat,
     * admin email included, sitting in the database.
     */
    public function test_it_erases_the_raw_payloads_too(): void
    {
        $this->seedOneSite();

        $this->assertGreaterThan(0, RawPayload::acrossAccounts()->count());

        $this->artisan('telemetry:forget', ['--email' => 'owner@example.com', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, RawPayload::acrossAccounts()->count());
    }

    public function test_it_leaves_everybody_else_alone(): void
    {
        $this->seedOneSite('leaving.com', 'goodbye@leaving.com');
        $this->seedOneSite('staying.com', 'hello@staying.com');

        $this->artisan('telemetry:forget', ['--email' => 'goodbye@leaving.com', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('staying.com', Site::acrossAccounts()->sole()->canonical_url);
        $this->assertSame('hello@staying.com', EndUser::acrossAccounts()->sole()->email);
        $this->assertSame(1, Deactivation::acrossAccounts()->count());
        $this->assertGreaterThan(0, RawPayload::acrossAccounts()->count());
    }

    public function test_it_can_erase_a_single_site_by_url(): void
    {
        $this->seedOneSite('one.com', 'owner@one.com');

        // Given in a different form to the one recorded, because a deletion
        // request arrives as whatever the person typed.
        $this->artisan('telemetry:forget', ['--site' => 'http://www.one.com/', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, Site::acrossAccounts()->count());
        $this->assertSame(0, RawPayload::acrossAccounts()->count());
    }

    /**
     * End users do not cascade from sites and they hold the contact
     * details, so erasing a site by URL while leaving its owner behind
     * keeps an email address attached to nothing.
     */
    public function test_erasing_a_site_takes_its_owner_with_it(): void
    {
        $this->seedOneSite('one.com', 'owner@one.com');

        $this->artisan('telemetry:forget', ['--site' => 'https://one.com', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, EndUser::acrossAccounts()->count(), 'the owner was left stranded');
    }

    /**
     * But somebody running the plugin on three sites who asks for one to be
     * removed has not asked to be forgotten.
     */
    public function test_an_owner_with_other_sites_is_kept(): void
    {
        $this->track($this->project, ['url' => 'https://first.com', 'admin_email' => 'owner@shared.com'])->assertOk();
        $this->track($this->project, ['url' => 'https://second.com', 'admin_email' => 'owner@shared.com'])->assertOk();

        $this->assertSame(1, EndUser::acrossAccounts()->count());

        $this->artisan('telemetry:forget', ['--site' => 'https://first.com', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, EndUser::acrossAccounts()->count(), 'the owner still has a site');
        $this->assertSame('second.com', Site::acrossAccounts()->sole()->canonical_url);
    }

    /**
     * A dry run that under-reports is worse than no dry run: somebody reads
     * "end users 0" and approves a deletion they did not understand.
     */
    public function test_the_dry_run_reports_what_it_will_actually_delete(): void
    {
        $this->seedOneSite('one.com', 'owner@one.com');

        // Artisan::call rather than $this->artisan(), because the rendered
        // output is the thing under test here, not the exit code.
        Artisan::call('telemetry:forget', ['--email' => 'owner@one.com', '--dry-run' => true]);

        $this->assertMatchesRegularExpression('/end users[^0-9]*1/', Artisan::output());

        Artisan::call('telemetry:forget', ['--site' => 'https://one.com', '--dry-run' => true]);

        $this->assertMatchesRegularExpression('/end users[^0-9]*1/', Artisan::output());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->seedOneSite();

        $this->artisan('telemetry:forget', ['--email' => 'owner@example.com', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(1, Site::acrossAccounts()->count());
        $this->assertSame(1, EndUser::acrossAccounts()->count());
    }

    /**
     * "We hold nothing about you" is a valid answer to a deletion request,
     * and the operator has to be able to say it without a failure exit code.
     */
    public function test_an_unknown_address_succeeds_quietly(): void
    {
        $this->artisan('telemetry:forget', ['--email' => 'nobody@example.com', '--force' => true])
            ->expectsOutputToContain('Nothing held')
            ->assertSuccessful();
    }

    public function test_it_requires_exactly_one_target(): void
    {
        $this->artisan('telemetry:forget')->assertExitCode(2);

        $this->artisan('telemetry:forget', [
            '--email' => 'a@b.com',
            '--site' => 'https://b.com',
        ])->assertExitCode(2);
    }
}
