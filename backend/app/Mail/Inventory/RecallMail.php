<?php

namespace App\Mail\Inventory;

use App\Models\Inventory\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A batch/lot has been recalled. Sent to admins + the warehouse manager so the
 * affected stock is pulled and any downstream customers can be contacted.
 */
class RecallMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $productName,
        public Batch $batch,
        public string $body,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Recall] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.recall',
            with: [
                'headline' => $this->subjectLine,
                'product'  => $this->productName,
                'batch'    => $this->batch,
                'body'     => $this->body,
            ],
        );
    }
}
