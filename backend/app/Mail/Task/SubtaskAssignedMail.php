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
 * "This is yours now." The one task event people genuinely need to see outside
 * the app, so it goes to the new assignees directly rather than to everyone
 * watching. Carries the ancestry so the reader knows which piece of which job
 * they've just been handed.
 */
class SubtaskAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Task $task,
        public array $ancestry,
        public string $actorName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Task] {$this->actorName} assigned you: {$this->task->name}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tasks.subtask-assigned',
            with: [
                'task'     => $this->task,
                'ancestry' => $this->ancestry,
                'actor'    => $this->actorName,
                'headline' => "{$this->actorName} assigned you a task",
                'intro'    => 'You have been put on the task below. Its deadline and status are its own — finishing it does not close anything above it.',
            ],
        );
    }
}
