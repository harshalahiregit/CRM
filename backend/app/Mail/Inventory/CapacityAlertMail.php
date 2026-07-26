<?php

namespace App\Mail\Inventory;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily warehouse housekeeping digest: bins over their limit, and stock
 * sitting at a site with no bin recorded.
 *
 * One message for every warehouse rather than one per bin — this is a list to
 * work through, not an incident to react to.
 */
class CapacityAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array $sites from LayoutService::capacityAlerts() */
    public function __construct(
        public string $subjectLine,
        public array $sites,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Inventory] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.capacity-alert',
            with: ['headline' => $this->subjectLine, 'sites' => $this->sites],
        );
    }
}
