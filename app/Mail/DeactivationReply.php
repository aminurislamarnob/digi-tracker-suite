<?php

namespace App\Mail;

use App\Models\Deactivation;
use App\Models\EndUser;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * A reply to somebody who wrote to us on their way out.
 *
 * Only ever sent for a deactivation carrying an actual comment. That
 * restriction is what makes this message defensible: replying to feedback
 * somebody chose to write is transactional and expected, whereas mailing
 * everyone who dismissed a dialog would be using telemetry consent as
 * cover for correspondence they never agreed to.
 */
class DeactivationReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Deactivation $deactivation,
        public Project $project,
        public EndUser $endUser,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            /*
             * From our authenticated domain -- a platform cannot inherit
             * each customer's DKIM -- but wearing the author's name, with
             * replies routed to the author's own inbox. The recipient wrote
             * to the plugin, not to us.
             */
            from: new Address(
                config('mail.from.address'),
                $this->project->from_name ?: $this->project->name,
            ),
            replyTo: array_values(array_filter([
                $this->project->reply_to
                    ? new Address($this->project->reply_to, $this->project->from_name ?: $this->project->name)
                    : null,
            ])),
            subject: 'Thanks for telling us why you removed '.$this->project->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.deactivation-reply', with: [
            'comment' => $this->deactivation->reason_info,
            'reasonLabel' => $this->deactivation->reasonLabel(),
            'project' => $this->project,
            'footer' => $this->project->email_footer,
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ]);
    }

    /**
     * One-click unsubscribe, so a mail client can honour it without the
     * recipient hunting for a link. RFC 8058 -- the POST variant is what
     * Gmail and Apple Mail actually act on.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function unsubscribeUrl(): string
    {
        /*
         * Signed, so the link cannot be edited into somebody else's
         * address. Unexpiring on purpose: a dead unsubscribe link is worse
         * than none, because the recipient's only remaining option is to
         * mark the message as spam.
         *
         * The token is the blind index, not the address -- the URL ends up
         * in mail logs and browser history.
         */
        return URL::signedRoute('email.unsubscribe', [
            'account' => $this->project->account_id,
            'token' => EndUser::indexFor($this->endUser->email),
        ]);
    }
}
