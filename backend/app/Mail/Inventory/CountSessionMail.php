<?php

namespace App\Mail\Inventory;

use App\Models\Inventory\CountSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The physical-count notices: a sheet assigned, a sheet awaiting a signature,
 * and the decision on it.
 *
 * When a variance summary is passed, the mail leads with the discrepancies
 * rather than the full sheet — an approver reading this on a phone needs the
 * six lines that disagree, not the four hundred that matched.
 */
class CountSessionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public CountSession $session,
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
        // Assigned sheets show what to walk; submitted ones show what disagreed.
        $lines = $this->summary
            ? []
            : $this->session->lines()->with(['product:id,sku,name', 'location:id,name,code'])->limit(40)->get();

        return new Content(
            view: 'emails.inventory.count-session',
            with: [
                'session'  => $this->session,
                'headline' => $this->subjectLine,
                'body'     => $this->body,
                'summary'  => $this->summary,
                'lines'    => $lines,
            ],
        );
    }
}
