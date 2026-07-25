<?php

namespace App\Mail\Inventory;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily "use it or lose it" digest — batches at or near their expiry date.
 * One mail covering everything rather than one per batch: expiry is a shopping
 * list you work through, not an incident you respond to.
 */
class ExpiryAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  array<int,\App\Models\Inventory\Batch>  $batches */
    public function __construct(
        public string $subjectLine,
        public array $batches,
        public int $withinDays,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Inventory] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.expiry-alert',
            with: [
                'batches'  => $this->batches,
                'days'     => $this->withinDays,
                'headline' => $this->subjectLine,
            ],
        );
    }
}
