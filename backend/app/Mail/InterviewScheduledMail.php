<?php

namespace App\Mail;

use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InterviewScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public $interview;
    public $candidate;
    public $recipientType; // 'candidate' or 'interviewer'

    /**
     * Create a new message instance.
     */
    public function __construct(HrInterviewRound $interview, $recipientType = 'candidate')
    {
        $this->interview = $interview;
        $this->candidate = $interview->candidate;
        $this->recipientType = $recipientType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->recipientType === 'candidate'
            ? 'Interview Scheduled - ' . $this->interview->round_name
            : 'New Interview Scheduled - ' . $this->candidate->name;

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $view = $this->recipientType === 'candidate'
            ? 'emails.interview-candidate'
            : 'emails.interview-interviewer';

        return new Content(
            view: $view,
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
