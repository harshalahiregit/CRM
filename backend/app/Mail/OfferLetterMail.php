<?php

namespace App\Mail;

use App\Models\Hr\HrOffer;
use App\Models\Hr\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OfferLetterMail extends Mailable
{
    use Queueable, SerializesModels;

    public $offer;
    public $candidate;

    /** Secure offer-portal link (nullable for legacy sends). */
    public $portalLink;

    /**
     * Create a new message instance.
     */
    public function __construct(HrOffer $offer, ?string $portalLink = null)
    {
        $this->offer = $offer;
        $this->candidate = $offer->candidate;
        $this->portalLink = $portalLink;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Job Offer - ' . $this->offer->position,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.offer-letter',
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
