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
 * Internal notice about something that happened to a stock document — an
 * incoming transfer to receive, or a posted document someone else cancelled.
 *
 * Distinct from VoucherNoteMail, which is the outward-facing copy sent to a
 * supplier or customer: this one is written for a colleague and links back into
 * the app rather than reading as a paper note.
 */
class VoucherActivityMail extends Mailable implements ShouldQueue
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
        return new Envelope(subject: '[Inventory] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inventory.voucher-activity',
            with: [
                'voucher'  => $this->voucher,
                'body'     => $this->body,
                'headline' => $this->subjectLine,
                'lines'    => $this->voucher->items()->with('product:id,sku,name')->get(),
            ],
        );
    }
}
