<?php

namespace App\Mail\Inventory;

use App\Models\Inventory\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The consignment notices: dispatched, short on arrival, and written off.
 *
 * A dispatch mail carries the manifest — the receiving site needs to know what
 * to expect before the lorry gets there. A shortfall mail leads with what did
 * not arrive, because that is the only part anyone will act on.
 */
class TransferMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Transfer $transfer,
        public string $subjectLine,
        public string $body,
        public ?array $summary = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Inventory] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.transfer',
            with: [
                'transfer' => $this->transfer,
                'headline' => $this->subjectLine,
                'body'     => $this->body,
                'summary'  => $this->summary,
                'lines'    => $this->transfer->lines()->with('product:id,sku,name')->limit(40)->get(),
            ],
        );
    }
}
