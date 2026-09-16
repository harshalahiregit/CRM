<?php

namespace App\Support\Task;

use App\Models\Task\Task;
use App\Support\RichText;

/**
 * One task, shaped for a VENDOR PORTAL.
 *
 * ── Why this exists ─────────────────────────────────────────────────────
 * Both portals used to return five fields for a task — id, name, status,
 * priority, due date — and nothing else. A vendor could see that a task existed
 * and change its status; they could not read what was being asked of them, and
 * they had no way to answer. "The task is just shown, how does it get involved?"
 * was a fair description of the whole feature.
 *
 * This is the fuller view: the brief, the checklist, the conversation and the
 * files. A shared PRESENTER, not shared vendor logic — exactly like
 * VendorTaskLink, which already reads both modules' links with one query shape.
 * Ownership is decided by the caller, before this is ever reached; nothing here
 * checks permissions, and nothing here knows which portal it is serving.
 *
 * ── What is deliberately NOT here ───────────────────────────────────────
 * No assignee names, no internal reminders, no time logs. A vendor is being told
 * what to do and given a way to reply; who inside the company is on it, and what
 * it costs, is not theirs to see. `file_path` never leaves the server either —
 * TaskFile hides it, and downloads go back through an authenticated route.
 */
final class PortalTaskView
{
    /**
     * @param  bool  $canWrite  whether the caller may comment/attach — drives the
     *                          composer in the UI, and is enforced by the route.
     */
    public static function detail(Task $task, bool $canWrite = true): array
    {
        $task->loadMissing([
            'checklistItems',
            'comments.user:id,name',
            'comments.attachments',
            'files.uploader:id,name',
        ]);

        return [
            'id'       => $task->id,
            'name'     => $task->name,
            // Both forms, the established portal convention: `description` is safe
            // to print anywhere, `description_html` is what the page renders.
            'description'      => RichText::toText($task->description),
            'description_html' => RichText::display($task->description),
            'status'   => $task->status,
            'priority' => $task->priority,
            'start_date'  => optional($task->start_date)->toDateString(),
            'due_date'    => optional($task->due_date)->toDateString(),
            'finished_at' => optional($task->date_finished)->toDateString(),
            'can_write'   => $canWrite,

            // Read-only. The checklist is how the work was broken up internally;
            // showing it tells the vendor what "done" means. Ticking it is a
            // staff action and stays one.
            'checklist' => $task->checklistItems->map(fn ($i) => [
                'id'          => $i->id,
                'description' => $i->description,
                'finished'    => (bool) $i->finished,
            ])->values(),

            'comments' => $task->comments->sortBy('id')->map(fn ($c) => [
                'id'         => $c->id,
                'author'     => $c->author_label,
                'is_vendor'  => $c->is_vendor_author,
                'body'       => RichText::toText($c->content),
                'body_html'  => RichText::display($c->content),
                'created_at' => optional($c->created_at)->toIso8601String(),
                'attachments' => $c->attachments->map(fn ($f) => self::file($f))->values(),
            ])->values(),

            // Task-level files only; a comment's attachments render under that
            // comment, which is where they were posted.
            'files' => $task->files->whereNull('comment_id')->map(fn ($f) => self::file($f))->values(),
        ];
    }

    private static function file($f): array
    {
        return [
            'id'         => $f->id,
            'name'       => $f->file_name,
            'size'       => (int) $f->file_size,
            'mime_type'  => $f->mime_type,
            'author'     => $f->author_label,
            'created_at' => optional($f->created_at)->toIso8601String(),
        ];
    }
}
