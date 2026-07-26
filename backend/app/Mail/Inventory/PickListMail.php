<?php

namespace App\Mail\Inventory;

use App\Models\Inventory\PickList;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The pick-pack-ship notices: a job assigned to a picker, and a parcel gone.
 *
 * Carries the lines with their suggested bin and batch, so a picker who reads
 * this on a phone already knows the route before opening the app.
 */
class PickListMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PickList $list,
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
            view: 'emails.inventory.pick-list',
            with: [
                'list'     => $this->list,
                'body'     => $this->body,
                'headline' => $this->subjectLine,
                'lines'    => $this->list->lines()
                    ->with(['product:id,sku,name', 'location:id,name,code', 'batch:id,batch_no,expiry_date'])
                    ->get(),
            ],
        );
    }
}
