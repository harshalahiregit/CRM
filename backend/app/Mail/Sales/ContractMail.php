<?php

namespace App\Mail\Sales;

use App\Models\Sales\SalesContract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SalesContract $contract,
        public string $bodyHtml,
        public string $portalUrl,
        private string $pdfBinary,
        private string $subjectLine,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sales.contract');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, "contract-{$this->contract->reference_no}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
