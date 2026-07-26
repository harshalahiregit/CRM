<?php

namespace App\Mail\Sales;

use App\Models\Sales\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string $bodyHtml   pre-sanitized HTML body written in the submit modal
     * @param string $portalUrl  public share link
     * @param string $pixelUrl   open-tracking pixel URL
     * @param string $pdfBinary  rendered proposal PDF
     */
    /**
     * @param array $extraAttachments  user-added files: [['name','mime','content'(raw bytes)], …]
     */
    public function __construct(
        public Proposal $proposal,
        public string $bodyHtml,
        public string $portalUrl,
        public string $pixelUrl,
        private string $pdfBinary,
        private string $subjectLine,
        private array $extraAttachments = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sales.proposal');
    }

    public function attachments(): array
    {
        // The rendered proposal PDF is always attached.
        $out = [
            Attachment::fromData(fn () => $this->pdfBinary, "proposal-{$this->proposal->id}.pdf")
                ->withMime('application/pdf'),
        ];

        // Plus any files the sender added in the submit modal.
        foreach ($this->extraAttachments as $a) {
            $out[] = Attachment::fromData(fn () => $a['content'], $a['name'])
                ->withMime($a['mime'] ?: 'application/octet-stream');
        }

        return $out;
    }
}
