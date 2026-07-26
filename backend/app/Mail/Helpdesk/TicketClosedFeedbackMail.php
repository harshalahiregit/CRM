<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class TicketClosedFeedbackMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public array $customer)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "How did we do? · Ticket #{$this->ticket->id} resolved",
        );
    }

    public function content(): Content
    {
        // One signed, expiring URL per star. The signature is the authorization
        // for the otherwise-public one-click endpoint, so links can't be forged.
        $stars = collect(range(1, 5))->map(fn ($n) => [
            'value' => $n,
            'url'   => URL::temporarySignedRoute(
                'helpdesk.feedback.oneclick',
                now()->addDays(30),
                ['ticket' => $this->ticket->id, 'rating' => $n],
            ),
        ])->all();

        // Signed, expiring one-click reopen — same trust model as the star links.
        // Lets a customer who isn't happy reopen the ticket straight from the
        // email without logging in.
        $reopenUrl = URL::temporarySignedRoute(
            'helpdesk.reopen.oneclick',
            now()->addDays(30),
            ['ticket' => $this->ticket->id],
        );

        return new Content(
            view: 'emails.helpdesk.ticket-closed-feedback',
            with: [
                'ticket'       => $this->ticket,
                'customerName' => $this->customer['name'] ?? 'there',
                'stars'        => $stars,
                'reopenUrl'    => $reopenUrl,
            ],
        );
    }
}
