<?php

namespace App\Services\Task;

use App\Mail\Task\SubtaskAssignedMail;
use App\Mail\Task\SubtaskCompletedMail;
use App\Mail\Task\TaskActivityMail;
use App\Mail\Task\TaskDueMail;
use App\Models\Task\Task;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every task alert — in-app bell and email — is decided here.
 *
 * Three rules the rest of the module relies on:
 *
 *  1. AUDIENCE CLIMBS THE TREE. A subtask's watchers are its own assignees and
 *     followers PLUS the root task's watchers. Someone who owns "Website" cares
 *     that "Pick colours" four levels down just got finished; without the climb
 *     they'd never hear about work inside their own task.
 *
 *  2. DELIVERY IS BEST-EFFORT. Both channels are swallow-logged, so a dead SMTP
 *     host or a full notifications table can never roll back the task write that
 *     triggered the alert.
 *
 *  3. NOBODY HEARS ABOUT THEIR OWN ACTION. notify() drops self-notification and
 *     the mail audience filters the actor out, so ticking your own subtask
 *     doesn't email you about it.
 *
 * Categories are individually switchable in Task settings, and `email_enabled`
 * is the master outbound switch.
 */
class TaskNotifier
{
    public function __construct(
        private NotificationService $notifications,
        private TaskConfigService $config,
        private TaskTreeService $tree,
    ) {
    }

    /* ── Subtask events ─────────────────────────────────────────── */

    /** A child was added under a task. */
    public function subtaskAdded(Task $child, Task $parent, int $actorId): void
    {
        if (! $this->config->on($child->tenant_id, 'notify_activity')) {
            return;
        }

        $actor = $this->name($actorId);

        $this->bell(
            $this->audience($child, $actorId),
            $child->tenant_id, 'task.subtask_added',
            "{$actor} added a subtask under: {$parent->name}",
            $child->name,
            $this->link($child),
            $actorId,
        );
    }

    /**
     * Someone was put on a subtask. This is the one event people genuinely need
     * outside the app, so it emails the new assignees directly rather than the
     * whole watcher list.
     *
     * @param  int[]  $userIds  newly added assignees
     */
    public function subtaskAssigned(Task $task, array $userIds, int $actorId): void
    {
        $userIds = array_values(array_diff(array_map('intval', $userIds), [$actorId]));
        if (! $userIds || ! $this->config->on($task->tenant_id, 'notify_assigned')) {
            return;
        }

        $actor = $this->name($actorId);
        $trail = $this->trail($task);

        $this->bell(
            $userIds, $task->tenant_id, 'task.subtask_assigned',
            "{$actor} assigned you: {$task->name}",
            $trail, $this->link($task), $actorId,
        );

        $this->mail(
            $task->tenant_id, $this->emails($userIds),
            fn () => new SubtaskAssignedMail($task, $this->tree->ancestryOf($task, $task->tenant_id), $actor),
            "assignment of task {$task->id}",
        );
    }

    /**
     * A checklist item was assigned to someone. Checklist items previously had NO
     * notification at all — this gives them the same in-app bell + email leg as a
     * subtask assignment. Gated by notify_assigned; self-assignment is silent.
     */
    public function checklistAssigned(Task $task, string $description, ?int $userId, int $actorId): void
    {
        $userId = $userId ? (int) $userId : null;
        if (! $userId || $userId === $actorId || ! $this->config->on($task->tenant_id, 'notify_assigned')) {
            return;
        }

        $actor = $this->name($actorId);
        $item  = trim($description) !== '' ? mb_strimwidth(trim($description), 0, 120, '…') : 'a checklist item';

        $this->bell(
            [$userId], $task->tenant_id, 'task.checklist_assigned',
            "{$actor} assigned you a checklist item",
            "{$item} — on \"{$task->name}\"",
            $this->link($task), $actorId,
        );

        $this->mail(
            $task->tenant_id, $this->emails([$userId]),
            fn () => new TaskActivityMail(
                $task, $this->tree->ancestryOf($task, $task->tenant_id),
                'You were assigned a checklist item', "{$item}\n\nTask: {$task->name}",
            ),
            "checklist assignment on task {$task->id}",
        );
    }

    /**
     * A subtask was finished. Goes UP the tree — the people who own the work
     * this sits inside are the ones who care that it closed.
     */
    public function subtaskCompleted(Task $task, int $actorId): void
    {
        if (! $task->parent_id || ! $this->config->on($task->tenant_id, 'notify_completed')) {
            return;   // a top-level task closing is ordinary status activity
        }

        $actor = $this->name($actorId);
        $audience = $this->audience($task, $actorId);
        $progress = $this->safeProgress($task);

        $this->bell(
            $audience, $task->tenant_id, 'task.subtask_completed',
            "{$actor} completed: {$task->name}",
            $this->trail($task).($progress ? " — {$progress['percent']}% of the parent done" : ''),
            $this->link($task), $actorId,
        );

        $this->mail(
            $task->tenant_id, $this->emails($audience),
            fn () => new SubtaskCompletedMail($task, $this->tree->ancestryOf($task, $task->tenant_id), $actor, $progress),
            "completion of task {$task->id}",
        );
    }

    /**
     * Status changed, commented, mentioned — the running commentary. The bell
     * for these already existed; this adds the email leg and the tree climb.
     *
     * @param  int[]  $userIds  recipients the caller already worked out
     */
    public function activity(Task $task, array $userIds, string $type, string $title, ?string $body, int $actorId): void
    {
        $userIds = array_values(array_diff(array_map('intval', $userIds), [$actorId]));
        if (! $userIds || ! $this->config->on($task->tenant_id, 'notify_activity')) {
            return;
        }

        $this->mail(
            $task->tenant_id, $this->emails($userIds),
            fn () => new TaskActivityMail($task, $this->tree->ancestryOf($task, $task->tenant_id), $title, $body),
            "activity on task {$task->id}",
        );
    }

    /** Deadline nudge. The bell for this is fired by the scheduler already. */
    public function due(Task $task, array $userIds, bool $overdue): void
    {
        $userIds = array_values(array_map('intval', $userIds));
        if (! $userIds || ! $this->config->on($task->tenant_id, 'notify_due')) {
            return;
        }

        $this->mail(
            $task->tenant_id, $this->emails($userIds),
            fn () => new TaskDueMail($task, $this->tree->ancestryOf($task, $task->tenant_id), $overdue),
            ($overdue ? 'overdue' : 'due-soon')." notice for task {$task->id}",
        );
    }

    /* ── Audience ───────────────────────────────────────────────── */

    /**
     * Who hears about something that happened to this task: its own assignees,
     * followers and creator, PLUS the same three on the root of its tree.
     *
     * @return int[]
     */
    public function audience(Task $task, ?int $excludeUserId): array
    {
        $ids = $this->watchers($task);

        if ($task->root_id && (int) $task->root_id !== (int) $task->id) {
            $root = Task::forTenant($task->tenant_id)->find($task->root_id);
            if ($root) {
                $ids = array_merge($ids, $this->watchers($root));
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        return $excludeUserId ? array_values(array_diff($ids, [(int) $excludeUserId])) : $ids;
    }

    /** @return int[] */
    private function watchers(Task $task): array
    {
        return $task->assignees()->pluck('user_id')
            ->merge($task->followers()->pluck('user_id'))
            ->push($task->created_by)
            ->filter()
            ->map(fn ($i) => (int) $i)
            ->unique()->values()->all();
    }

    private function emails(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        return User::whereIn('id', $userIds)
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('email')->unique()->values()->all();
    }

    /* ── Delivery ───────────────────────────────────────────────── */

    /** @param int[] $userIds */
    private function bell(array $userIds, int $tenantId, string $type, string $title, ?string $message, ?string $link, ?int $actorId): void
    {
        foreach ($userIds as $uid) {
            $this->notifications->notify($uid, $tenantId, $type, $title, $message, $link, $actorId);
        }
    }

    /**
     * Send one mailable to a list of addresses, honouring the master switch and
     * the workspace's extra alert inbox. The mailable is built lazily so we never
     * pay for it when email is off or there's nobody to tell.
     */
    private function mail(int $tenantId, array $addresses, callable $make, string $what): void
    {
        if (! $this->config->on($tenantId, 'email_enabled')) {
            return;
        }

        $extra = $this->config->get($tenantId, 'alert_email_extra');
        if ($extra) {
            $addresses[] = $extra;
        }

        $addresses = array_values(array_unique(array_filter($addresses)));
        if (! $addresses) {
            return;
        }

        try {
            Mail::to($addresses)->send($make());
        } catch (\Throwable $e) {
            Log::warning("Task mail failed ({$what}): {$e->getMessage()}");
        }
    }

    /* ── Small helpers ──────────────────────────────────────────── */

    /** "Website › Design › Homepage mockup" — where in the tree this sits. */
    private function trail(Task $task): string
    {
        try {
            $chain = $this->tree->ancestryOf($task, $task->tenant_id);

            return implode(' › ', array_column($chain, 'name'));
        } catch (\Throwable $e) {
            return $task->name;
        }
    }

    private function safeProgress(Task $task): ?array
    {
        try {
            $parent = Task::forTenant($task->tenant_id)->find($task->parent_id);

            return $parent ? $this->tree->progressForTask($parent, $task->tenant_id) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function name(?int $userId): string
    {
        return $userId ? (User::whereKey($userId)->value('name') ?: 'Someone') : 'Someone';
    }

    private function link(Task $task): string
    {
        return "/app/tasks/{$task->id}";
    }
}
