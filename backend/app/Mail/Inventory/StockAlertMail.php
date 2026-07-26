<?php

namespace App\Mail\Inventory;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You're about to run out." Sent the moment a posting pushes one or more items
 * to or below their reorder point — the point of the email is that the reader is
 * probably not looking at the app when it matters.
 */
class StockAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int,array{product:\App\Models\Inventory\Product,on_hand:float,threshold:float}>  $items
     */
    public function __construct(
        public string $subjectLine,
        public array $items,
        public bool $isOutOfStock,
        public ?string $warehouseName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->isOutOfStock ? '[Out of stock] ' : '[Low stock] ').$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.stock-alert',
            with: [
                'items'     => $this->items,
                'critical'  => $this->isOutOfStock,
                'warehouse' => $this->warehouseName,
                'headline'  => $this->subjectLine,
            ],
        );
    }
}
