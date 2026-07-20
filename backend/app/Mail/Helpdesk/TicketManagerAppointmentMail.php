<?php

namespace App\Mail\Helpdesk;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells someone the admin has appointed (or removed) them as a ticket manager for
 * a scope — a specific department, or the whole helpdesk. Queued like every other
 * helpdesk mail so appointing a manager never blocks the settings save.
 */
class TicketManagerAppointmentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $scopeLabel,     // e.g. "the Technical department" / "the whole helpdesk"
        public bool $appointed,        // true = added, false = removed
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->appointed
            ? "You're now a ticket manager for {$this->scopeLabel}"
            : "You've been removed as a ticket manager for {$this->scopeLabel}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $appUrl = rtrim((string) config('helpdesk.app_url'), '/');

        return new Content(
            view: 'emails.helpdesk.ticket-manager-appointment',
            with: [
                'recipientName' => $this->recipientName,
                'scopeLabel'    => $this->scopeLabel,
                'appointed'     => $this->appointed,
                'helpdeskUrl'   => "{$appUrl}/app/helpdesk/tickets",
            ],
        );
    }
}
