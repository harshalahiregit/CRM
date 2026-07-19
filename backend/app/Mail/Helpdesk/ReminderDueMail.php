<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You asked to be reminded about this ticket" — the email half of a due ticket
 * reminder, sent alongside the in-app bell by helpdesk:run-reminders. Reminders
 * are self-set by an agent, so the recipient is always the reminder's owner.
 */
class ReminderDueMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public TicketReminder $reminder,
        public Ticket $ticket,
        public string $agentName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] reminder due — {$this->ticket->subject}",
        );
    }

    public function content(): Content
    {
        $appUrl = rtrim((string) config('helpdesk.app_url'), '/');

        return new Content(
            view: 'emails.helpdesk.reminder-due',
            with: [
                'reminder'  => $this->reminder,
                'ticket'    => $this->ticket,
                'agentName' => $this->agentName,
                'ticketUrl' => "{$appUrl}/app/helpdesk/tickets/{$this->ticket->id}",
            ],
        );
    }
}
