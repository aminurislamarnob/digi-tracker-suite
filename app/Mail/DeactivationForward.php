<?php

namespace App\Mail;

use App\Models\Deactivation;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A copy of the feedback, to the author's own inbox.
 *
 * Sent for every deactivation, comment or not -- unlike the reply. This
 * one goes to the author, who is our customer and has asked for it; the
 * volume question is theirs, and a bare "found a better plugin" with no
 * comment is still a churn signal worth seeing.
 *
 * Reply-To is the departing user where we have their address, so hitting
 * reply reaches the person who left rather than us. That is the entire
 * point of forwarding it rather than leaving it in the dashboard.
 */
class DeactivationForward extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Deactivation $deactivation,
        public Project $project,
        public ?string $userEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        $site = $this->deactivation->site?->canonical_url ?? 'a site';

        return new Envelope(
            from: new Address(config('mail.from.address'), 'Digi Tracker'),
            replyTo: array_values(array_filter([
                $this->userEmail && filter_var($this->userEmail, FILTER_VALIDATE_EMAIL)
                    ? new Address($this->userEmail)
                    : null,
            ])),
            subject: $this->project->name.' removed on '.$site,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.deactivation-forward', with: [
            'deactivation' => $this->deactivation,
            'project' => $this->project,
            'reasonLabel' => $this->deactivation->reasonLabel() ?? 'Not given',
            'userEmail' => $this->userEmail,
            'site' => $this->deactivation->site?->canonical_url,
        ]);
    }
}
