<?php

namespace App\Services;

use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use App\Models\EndUser;
use App\Models\Project;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every message this platform sends goes through here.
 *
 * One choke point, because the interesting part of an email layer is not
 * the sending -- it is the refusals. Telemetry consent is consent to be
 * measured, not consent to be written to, and the distinction only holds
 * if there is a single place where it can be enforced.
 *
 * The refusals, in order:
 *
 *   1. Demo projects never send. Their addresses are invented, and mailing
 *      a made-up domain is at best a bounce and at worst a stranger.
 *   2. Suppressed addresses never send. Unsubscribe means unsubscribe.
 *   3. The relevant per-project switch must be on. Off by default: an
 *      email nobody deliberately enabled is an email nobody meant to send.
 */
class Mailer
{
    public function __construct(protected DashboardMetrics $metrics) {}

    /**
     * Send to an end user -- somebody who installed the plugin, not a
     * customer of ours. The most restricted path in the application.
     */
    public function toEndUser(EndUser $endUser, Project $project, Mailable $mailable, string $type): bool
    {
        if (! $this->maySend($project)) {
            return false;
        }

        $address = $endUser->email;

        if (! $address || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($this->isSuppressed($project->account_id, $address)) {
            return false;
        }

        return $this->dispatch($address, $mailable, $type, $project, $endUser);
    }

    /**
     * Send to the project's own inbox -- the author, not their users.
     *
     * Suppression still applies. An author who marked us as spam has said
     * something, and "it was only an internal forward" is not an answer.
     */
    public function toProjectInbox(Project $project, Mailable $mailable, string $type): bool
    {
        if (! $this->maySend($project)) {
            return false;
        }

        $address = $project->support_email ?: $project->reply_to;

        if (! $address || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            // Nowhere to send is a configuration gap, not an error. Say so
            // once rather than throwing on every deactivation.
            Log::info("[mail] {$type} skipped: project [{$project->slug}] has no support address.");

            return false;
        }

        if ($this->isSuppressed($project->account_id, $address)) {
            return false;
        }

        return $this->dispatch($address, $mailable, $type, $project);
    }

    /**
     * Demo projects hold invented telemetry, which means invented email
     * addresses on domains belonging to strangers.
     */
    protected function maySend(Project $project): bool
    {
        if ($project->is_demo) {
            Log::info("[mail] skipped: [{$project->slug}] is a demo project.");

            return false;
        }

        return true;
    }

    public function isSuppressed(int $accountId, string $email): bool
    {
        return EmailSuppression::acrossAccounts()
            ->where('account_id', $accountId)
            ->where('email_index', EndUser::indexFor($email))
            ->exists();
    }

    public function suppress(int $accountId, string $email, string $reason = EmailSuppression::UNSUBSCRIBED): void
    {
        EmailSuppression::acrossAccounts()->updateOrCreate(
            ['account_id' => $accountId, 'email_index' => EndUser::indexFor($email)],
            ['reason' => $reason],
        );
    }

    protected function dispatch(
        string $address,
        Mailable $mailable,
        string $type,
        Project $project,
        ?EndUser $endUser = null,
    ): bool {
        Mail::to($address)->send($mailable);

        EmailEvent::acrossAccounts()->create([
            'account_id' => $project->account_id,
            'project_id' => $project->id,
            'end_user_id' => $endUser?->id,
            'type' => $type,
            // Blind index, so the send log does not become a mailing list.
            'recipient_index' => EndUser::indexFor($address),
            'sent_at' => now(),
        ]);

        return true;
    }
}
