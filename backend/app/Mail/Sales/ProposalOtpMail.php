<?php

namespace App\Mail\Sales;

use App\Models\Sales\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Proposal $proposal, public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your verification code — '.$this->proposal->subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.sales.proposal-otp');
    }
}
