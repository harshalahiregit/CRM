<?php

namespace App\Mail\Sales;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A free-form email to a lead, composed in the lead's Email tab.
 *
 * The body arrives already sanitized by HtmlSanitizer — the composer is a rich
 * text editor, so it must not be escaped again on the way out.
 */
class LeadEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $bodyHtml,
        public string $leadName,
        private string $subjectLine,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sales.lead');
    }
}
