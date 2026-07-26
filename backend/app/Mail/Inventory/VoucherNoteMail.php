<?php

namespace App\Mail\Inventory;

use App\Models\Inventory\Voucher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a stock document to a supplier/customer (blueprint §2's
 * "send_received_note"). Queued like every other outbound mail in the app so a
 * slow SMTP hop never blocks the request that triggered it.
 */
class VoucherNoteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Voucher $voucher,
        public string $subjectLine,
        public string $body,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.voucher-note',
            with: [
                'voucher' => $this->voucher,
                'body'    => $this->body,
                'lines'   => $this->voucher->items()->with('product:id,sku,name')->get(),
            ],
        );
    }
}
