<?php

namespace App\Mail;

use App\Models\Hr\HrOnboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $onboarding;

    /** Public onboarding portal link (nullable for the legacy HR-checklist flow). */
    public $portalLink;

    /**
     * Create a new message instance.
     */
    public function __construct(HrOnboarding $onboarding, ?string $portalLink = null)
    {
        $this->onboarding = $onboarding;
        $this->portalLink = $portalLink;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎉 Congratulations! You have been selected at ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding-welcome',
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
