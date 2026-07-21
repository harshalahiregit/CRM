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
 * Deadline nudge. A subtask carries its OWN deadline — it does not inherit the
 * parent's — so this fires per node, and the ancestry in the body is what tells
 * the reader which piece of the job is actually late.
 */
class TaskDueMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Task $task,
        public array $ancestry,
        public bool $overdue,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->overdue ? '[Task overdue] ' : '[Task due soon] ').$this->task->name);
    }

    public function content(): Content
    {
        $when = $this->task->due_date ? $this->task->due_date->format('d M Y') : 'soon';

        return new Content(
            view: 'emails.tasks.due',
            with: [
                'task'     => $this->task,
                'ancestry' => $this->ancestry,
                'overdue'  => $this->overdue,
                'headline' => $this->overdue ? 'This task is overdue' : 'This task is due soon',
                'intro'    => $this->overdue
                    ? "The deadline was {$when} and the task is still open."
                    : "The deadline is {$when}.",
            ],
        );
    }
}
