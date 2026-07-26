<?php

namespace App\Mail\Settings;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sangoe CRM — test email',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.settings.test-mail');
    }
}
