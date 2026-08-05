<?php

namespace App\Console\Commands;

use App\Models\Project\ProjectNote;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fires due project-note reminders as in-app notifications. A note can carry a
 * remind_at + an assignee; this is the clock that turns that into a bell for the
 * assignee (or the author if unassigned). `reminded` is the idempotency guard, so
 * each due reminder converts to exactly one notification even across catch-up runs.
 */
class RunProjectNoteReminders extends Command
{
    protected $signature = 'projects:run-note-reminders';

    protected $description = 'Fire due project-note reminders as in-app notifications';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;

        ProjectNote::query()
            ->whereNotNull('remind_at')
            ->where('reminded', false)
            ->where('remind_at', '<=', now())
            ->chunkById(200, function (Collection $rows) use ($notifications, &$sent) {
                foreach ($rows as $note) {
                    $recipient = $note->assigned_to ?: $note->created_by;
                    if ($recipient) {
                        $notifications->notify(
                            userId: (int) $recipient,
                            tenantId: (int) $note->tenant_id,
                            type: 'project.note_reminder',
                            title: "Note reminder: {$note->title}",
                            message: null,
                            link: "/app/projects/{$note->project_id}",
                            actorId: null,
                        );
                    }
                    $note->forceFill(['reminded' => true])->save();
                    $sent++;
                }
            });

        $this->info("Projects: {$sent} note reminder(s) fired.");

        return self::SUCCESS;
    }
}
