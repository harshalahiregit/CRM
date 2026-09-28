<?php

namespace App\Mail\Contract;

use App\Models\Contract\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The contract, sent to the party who has to sign it.
 *
 * Carries two things: the PDF, so they have a copy that does not depend on our
 * server staying up, and the signing link, which is the only way they can
 * actually sign. Sending one without the other is the common failure -- a PDF
 * alone leaves them with nothing to click, and a link alone leaves them nothing
 * to keep or forward to whoever approves it on their side.
 *
 * The Mailable does NOT queue. The person pressing Send is standing there and
 * needs to be told whether it went; a queued send reports success before the
 * SMTP server has been spoken to.
 */
class ContractDispatchMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public string $bodyHtml,
        public string $signUrl,
        private string $pdfBinary,
        private string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contract.dispatch');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, "contract-{$this->contract->reference_no}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
