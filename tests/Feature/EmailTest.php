<?php

namespace Tests\Feature;

use App\Mail\DeactivationForward;
use App\Mail\DeactivationReply;
use App\Mail\WeeklyDigest;
use App\Models\Account;
use App\Models\DailyStat;
use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use App\Models\EndUser;
use App\Models\Project;
use App\Models\User;
use App\Services\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\PostsTelemetry;
use Tests\TestCase;

/**
 * Telemetry consent is consent to be measured, not consent to be written
 * to. Most of these tests are about refusals rather than delivery.
 */
class EmailTest extends TestCase
{
    use PostsTelemetry, RefreshDatabase;

    protected Account $account;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->account = Account::factory()->create();
        $this->project = Project::factory()->for($this->account)->create([
            'name' => 'Metadata Viewer',
            'support_email' => 'support@pluginizelab.com',
            'reply_to' => 'hello@pluginizelab.com',
            'replies_to_deactivations' => true,
            'forwards_deactivations' => true,
        ]);
    }

    protected function churn(string $comment = 'Needed multisite support.', string $email = 'owner@example.com'): void
    {
        $this->track($this->project, ['admin_email' => $email])->assertOk();

        $this->deactivate($this->project, [
            'admin_email' => $email,
            'reason_id' => 'not-have-that-feature',
            'reason_info' => $comment,
        ])->assertOk();
    }

    public function test_deactivating_replies_to_the_user_and_copies_the_author(): void
    {
        $this->churn();

        Mail::assertSent(DeactivationReply::class, fn ($mail) => $mail->hasTo('owner@example.com'));
        Mail::assertSent(DeactivationForward::class, fn ($mail) => $mail->hasTo('support@pluginizelab.com'));

        $this->assertSame(2, EmailEvent::acrossAccounts()->count());
    }

    /**
     * The line that makes the auto-responder defensible. Somebody who
     * dismissed the dialog without writing anything did not ask for a
     * reply, and telemetry consent is not permission to send one.
     */
    public function test_no_reply_without_a_comment(): void
    {
        $this->churn(comment: '');

        Mail::assertNotSent(DeactivationReply::class);

        // The author still gets the churn signal.
        Mail::assertSent(DeactivationForward::class);
    }

    /**
     * A second "thanks for your feedback" is not gratitude, it is a
     * mailing list.
     */
    public function test_a_person_is_only_ever_replied_to_once(): void
    {
        $this->churn('First time.');

        $this->travel(30)->days();
        $this->track($this->project, ['admin_email' => 'owner@example.com'])->assertOk();
        $this->deactivate($this->project, [
            'admin_email' => 'owner@example.com',
            'reason_id' => 'other',
            'reason_info' => 'Second time.',
        ])->assertOk();

        Mail::assertSent(DeactivationReply::class, 1);
    }

    public function test_nothing_is_sent_when_the_switches_are_off(): void
    {
        $this->project->update([
            'replies_to_deactivations' => false,
            'forwards_deactivations' => false,
        ]);

        $this->churn();

        Mail::assertNothingSent();
        $this->assertSame(0, EmailEvent::acrossAccounts()->count());
    }

    /**
     * Demo telemetry carries invented addresses on domains belonging to
     * strangers. Mailing them is at best a bounce.
     */
    public function test_demo_projects_never_send(): void
    {
        $this->project->update(['is_demo' => false]);
        $this->churn();
        Mail::assertSent(DeactivationReply::class);

        Mail::fake();

        $demo = Project::factory()->for($this->account)->create([
            'is_demo' => true,
            'replies_to_deactivations' => true,
            'forwards_deactivations' => true,
            'support_email' => 'support@pluginizelab.com',
        ]);

        // Ingest refuses demo projects, so drive the reconciler's own path.
        $demo->update(['is_demo' => false]);
        $this->track($demo, ['url' => 'https://demo-site.com', 'admin_email' => 'd@demo-site.com'])->assertOk();
        $demo->update(['is_demo' => true]);

        $this->deactivate($demo, ['url' => 'https://demo-site.com', 'admin_email' => 'd@demo-site.com'])
            ->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_a_suppressed_address_is_never_written_to(): void
    {
        app(Mailer::class)->suppress($this->account->id, 'owner@example.com');

        $this->churn();

        Mail::assertNotSent(DeactivationReply::class);

        // Suppressing the user does not silence the author's own copy.
        Mail::assertSent(DeactivationForward::class);
    }

    /**
     * An unsubscribe link that needs a login, or a confirmation click, is
     * the thing that makes people press the spam button instead.
     */
    public function test_unsubscribe_works_from_the_link_alone(): void
    {
        $this->churn();

        $url = null;

        Mail::assertSent(DeactivationReply::class, function ($mail) use (&$url) {
            $url = $mail->unsubscribeUrl();

            return true;
        });

        $this->get($url)->assertOk()->assertSee('unsubscribed');

        $this->assertDatabaseHas('email_suppressions', [
            'account_id' => $this->account->id,
            'email_index' => EndUser::indexFor('owner@example.com'),
            'reason' => EmailSuppression::UNSUBSCRIBED,
        ]);
    }

    /** RFC 8058 one-click: the mail client POSTs, with nobody present. */
    public function test_one_click_unsubscribe_accepts_a_post(): void
    {
        $this->churn();

        $url = null;
        Mail::assertSent(DeactivationReply::class, function ($mail) use (&$url) {
            $url = $mail->unsubscribeUrl();

            return true;
        });

        $this->post($url)->assertOk();

        $this->assertSame(1, EmailSuppression::acrossAccounts()->count());
    }

    public function test_a_tampered_unsubscribe_link_is_refused(): void
    {
        $this->get(route('email.unsubscribe', [
            'account' => $this->account->id,
            'token' => EndUser::indexFor('someone-else@example.com'),
        ]))->assertForbidden();

        $this->assertSame(0, EmailSuppression::acrossAccounts()->count());
    }

    public function test_the_reply_carries_one_click_unsubscribe_headers(): void
    {
        $this->churn();

        Mail::assertSent(DeactivationReply::class, function (DeactivationReply $mail) {
            $headers = $mail->headers()->text;

            return isset($headers['List-Unsubscribe'])
                && ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click';
        });
    }

    /**
     * The author's name in From, their address in Reply-To. The recipient
     * wrote to the plugin, not to us.
     */
    public function test_the_reply_wears_the_projects_identity(): void
    {
        $this->project->update(['from_name' => 'PluginizeLab']);

        $this->churn();

        Mail::assertSent(DeactivationReply::class, function (DeactivationReply $mail) {
            $envelope = $mail->envelope();

            return $envelope->from->name === 'PluginizeLab'
                && $envelope->replyTo[0]->address === 'hello@pluginizelab.com';
        });
    }

    public function test_the_digest_goes_to_the_account_team(): void
    {
        $user = User::factory()->create();
        $user->accounts()->attach($this->account, ['role' => 'owner']);

        $this->project->update(['sends_weekly_digest' => true]);

        DailyStat::acrossAccounts()->create([
            'account_id' => $this->account->id,
            'project_id' => $this->project->id,
            'date' => today(),
            'active_installs' => 120,
            'by_version' => ['2.2.4' => 90, '2.1.0' => 30],
        ]);

        $this->artisan('telemetry:send-digests')->assertSuccessful();

        Mail::assertSent(WeeklyDigest::class, fn ($mail) => $mail->hasTo($user->email));
    }

    /**
     * A digest confidently reporting zero because a cron missed is worse
     * than no digest.
     */
    public function test_no_digest_without_a_rollup(): void
    {
        $user = User::factory()->create();
        $user->accounts()->attach($this->account, ['role' => 'owner']);

        $this->project->update(['sends_weekly_digest' => true]);

        $this->artisan('telemetry:send-digests')
            ->expectsOutputToContain('no rollup yet')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * The suppression list is the one table where plaintext addresses would
     * be least defensible: its whole purpose is that we stopped writing to
     * those people.
     */
    public function test_no_address_is_stored_in_the_clear(): void
    {
        $this->churn();

        app(Mailer::class)->suppress($this->account->id, 'owner@example.com');

        foreach (EmailSuppression::acrossAccounts()->get() as $row) {
            $this->assertStringNotContainsString('@', $row->email_index);
        }

        foreach (EmailEvent::acrossAccounts()->get() as $row) {
            $this->assertStringNotContainsString('@', (string) $row->recipient_index);
        }
    }
}
