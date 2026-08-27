<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The week, to the author.
 *
 * Goes to our own customer, so it carries no unsubscribe machinery beyond
 * the per-project switch that turned it on -- they can turn it off in the
 * same place they turned it on.
 *
 * Deliberately short. A digest that reprints the dashboard gets filtered;
 * one that says what changed gets read.
 */
class WeeklyDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public Project $project,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        $installs = number_format($this->summary['installs']);
        $delta = $this->summary['installsDelta'];

        $movement = $delta === null
            ? ''
            : ' ('.($delta > 0 ? '+' : '').number_format($delta).')';

        return new Envelope(
            from: new Address(config('mail.from.address'), 'Digi Tracker'),
            subject: $this->project->name.': '.$installs.' tracked installs'.$movement,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-digest', with: [
            'project' => $this->project,
            'summary' => $this->summary,
        ]);
    }
}
