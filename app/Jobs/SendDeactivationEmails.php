<?php

namespace App\Jobs;

use App\Mail\DeactivationForward;
use App\Mail\DeactivationReply;
use App\Models\Deactivation;
use App\Models\EmailEvent;
use App\Models\EndUser;
use App\Services\Mailer;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reacts to a churn event: reply to the person, copy the author.
 *
 * Queued and separate from reconciliation on purpose. A mail server being
 * slow, or down, must not turn a telemetry write into a failed job that
 * retries and re-reconciles -- the heartbeat was fire-and-forget and there
 * is no second chance to record it.
 */
class SendDeactivationEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deactivationId) {}

    public function handle(Mailer $mailer): void
    {
        CurrentAccount::withoutScope(function () use ($mailer) {
            $deactivation = Deactivation::with(['project', 'site'])->find($this->deactivationId);

            if (! $deactivation || ! $deactivation->project) {
                return;
            }

            $project = $deactivation->project;
            $endUser = $deactivation->site?->end_user_id
                ? EndUser::find($deactivation->site->end_user_id)
                : null;

            $this->reply($mailer, $deactivation, $project, $endUser);
            $this->forward($mailer, $deactivation, $project, $endUser);
        });
    }

    /**
     * The reply goes only to somebody who actually wrote something.
     *
     * This is the line that keeps the message defensible. Replying to
     * feedback a person chose to write is transactional and expected;
     * mailing everyone who dismissed a dialog would be treating telemetry
     * consent as permission to correspond, which it is not.
     *
     * Once per person per project, ever. A second "thanks for your
     * feedback" to someone who removed the plugin twice is not gratitude,
     * it is a mailing list.
     */
    protected function reply(Mailer $mailer, Deactivation $deactivation, $project, ?EndUser $endUser): void
    {
        if (! $project->replies_to_deactivations || ! $endUser) {
            return;
        }

        if (blank($deactivation->reason_info)) {
            return;
        }

        $alreadyWritten = EmailEvent::acrossAccounts()
            ->where('project_id', $project->id)
            ->where('end_user_id', $endUser->id)
            ->where('type', EmailEvent::DEACTIVATION_REPLY)
            ->exists();

        if ($alreadyWritten) {
            return;
        }

        $mailer->toEndUser(
            $endUser,
            $project,
            new DeactivationReply($deactivation, $project, $endUser),
            EmailEvent::DEACTIVATION_REPLY,
        );
    }

    /**
     * The forward goes for every deactivation, comment or not: a bare
     * "found a better plugin" is still a churn signal the author asked to
     * see, and they are the one who switched it on.
     */
    protected function forward(Mailer $mailer, Deactivation $deactivation, $project, ?EndUser $endUser): void
    {
        if (! $project->forwards_deactivations) {
            return;
        }

        $mailer->toProjectInbox(
            $project,
            new DeactivationForward($deactivation, $project, $endUser?->email),
            EmailEvent::DEACTIVATION_FORWARD,
        );
    }
}
