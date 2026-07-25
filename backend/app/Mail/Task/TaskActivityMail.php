<?php

namespace App\Mail\Task;

use App\Models\Task\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The running commentary — status changed, commented, mentioned. One mailable
 * for all of it because the shape is identical: a headline the caller already
 * composed for the bell, an optional excerpt, and a way back into the task.
 */
class TaskActivityMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Task $task,
        public array $ancestry,
        public string $headline,
        public ?string $body = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Task] {$this->headline}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tasks.activity',
            with: [
                'task'     => $this->task,
                'ancestry' => $this->ancestry,
                'headline' => $this->headline,
                'intro'    => $this->body ?: 'There has been activity on a task you are following.',
            ],
        );
    }
}
