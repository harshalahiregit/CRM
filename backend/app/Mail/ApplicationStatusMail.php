<?php

namespace App\Mail;

use App\Models\Hr\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public $candidate;
    public $newStage;

    /**
     * Custom message for the applicant.
     *
     * NOTE: this property is intentionally NOT named `message`. Laravel injects
     * the Illuminate\Mail\Message instance into every mail view as `$message`,
     * which would override a same-named public property and leak the object into
     * the Blade template (htmlspecialchars() on a Message → TypeError).
     */
    public $statusMessage;

    /**
     * Create a new message instance.
     */
    public function __construct(HrCandidate $candidate, string $newStage, string $statusMessage = '')
    {
        $this->candidate = $candidate;
        $this->newStage = $newStage;
        $this->statusMessage = $statusMessage;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Application Update - ' . $this->newStage,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.application-status',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
