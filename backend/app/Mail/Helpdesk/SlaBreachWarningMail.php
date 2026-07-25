<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Warns the assigned agent and the ticket's managers that an SLA clock is about
 * to run out (at_risk) or already has (breached). Sent by helpdesk:check-sla.
 *
 * $breaches is the already-computed SLA snapshot, passed in rather than
 * recomputed here: a Mailable must not depend on SlaService, and the sender has
 * the numbers anyway. Shape: [['clock' => 'response'|'resolution',
 * 'state' => 'at_risk'|'breached', 'due' => ISO8601 string], ...]
 */
class SlaBreachWarningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<int,array{clock:string,state:string,due:string}> $breaches */
    public function __construct(
        public Ticket $ticket,
        public string $recipientName,
        public array $breaches,
    ) {
    }

    public function envelope(): Envelope
    {
        // Lead with the worst state so the subject line reads true at a glance.
        $breached = collect($this->breaches)->contains(fn ($b) => $b['state'] === 'breached');
        $label = $breached ? 'SLA BREACHED' : 'SLA at risk';

        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] {$label} — {$this->ticket->subject}",
        );
    }

    public function content(): Content
    {
        $appUrl = rtrim((string) config('helpdesk.app_url'), '/');
        $breached = collect($this->breaches)->contains(fn ($b) => $b['state'] === 'breached');

        return new Content(
            view: 'emails.helpdesk.sla-breach-warning',
            with: [
                'ticket'        => $this->ticket,
                'recipientName' => $this->recipientName,
                'breaches'      => $this->breaches,
                'breached'      => $breached,
                'ticketUrl'     => "{$appUrl}/app/helpdesk/tickets/{$this->ticket->id}",
            ],
        );
    }
}
