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
 * A subtask closed. Sent UP the tree, because the value of this message is the
 * parent's new percentage — "Design is now 67% done" is the useful sentence, not
 * "someone ticked a box".
 */
class SubtaskCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Task $task,
        public array $ancestry,
        public string $actorName,
        public ?array $parentProgress = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Task] Completed: {$this->task->name}");
    }

    public function content(): Content
    {
        $pct = $this->parentProgress['percent'] ?? null;

        return new Content(
            view: 'emails.tasks.subtask-completed',
            with: [
                'task'     => $this->task,
                'ancestry' => $this->ancestry,
                'actor'    => $this->actorName,
                'progress' => $this->parentProgress,
                'headline' => "{$this->actorName} completed a subtask",
                'intro'    => $pct === null
                    ? 'A subtask inside your work has been finished.'
                    : "A subtask inside your work has been finished — the task above it is now {$pct}% complete.",
            ],
        );
    }
}
