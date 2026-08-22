<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\Ticket;
use App\Models\Hr\HrEmployee;
use App\Models\Project\Project;
use App\Models\Task\Task;
use Illuminate\Support\Facades\DB;

/**
 * Review comment #37 — "Project, Task, KB, Tickets should reflect in employee
 * lifecycle too and they can jump to the relevant section from here itself."
 *
 * READ-ONLY. Every row here is owned by its own module; this aggregates and
 * links, and creates nothing. There is deliberately no employee_project,
 * employee_task or employee_ticket table — that is the duplicate system the
 * comment rules out.
 *
 * THE JOIN IS `user_id`, NOT `employee_id`.
 * Projects, tasks and tickets all reference the USER who does the work. An
 * HrEmployee is linked to one via `hr_employees.user_id`, and that column is
 * nullable — an employee record can exist with no login. When it is null this
 * service returns `linked: false` and explains why, rather than returning four
 * empty lists that look like "this person has done nothing".
 *
 * KNOWLEDGE BASE IS THE EXCEPTION. `kb_articles` has no author or owner column,
 * so "articles this employee wrote" is not answerable from the schema. What IS
 * answerable is the articles for their DEPARTMENT, which is the useful reading of
 * "KB should reflect in the lifecycle" — and it is labelled as such rather than
 * implied to be authorship.
 */
class EmployeeLifecycleService
{
    /** Rows per section. The profile is a summary; each section links out for the rest. */
    private const LIMIT = 10;

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $userId = $employee->user_id;

        if (! $userId) {
            return [
                'employee_id' => $employee->id,
                'linked'      => false,
                'reason'      => 'This employee has no linked user account, so their projects, tasks and tickets '
                                 .'cannot be resolved. Link a user on the employee record to enable this.',
                'projects'    => $this->empty(), 'tasks' => $this->empty(),
                'tickets'     => $this->empty(),
                // The KB section does not depend on the user link — it is
                // department-based — so it is still populated.
                'knowledge_base' => $this->knowledgeBase($employee, $tenantId),
                // Same key in both branches so the caller never has to check.
                'activity'       => [],
            ];
        }

        $projects = $this->projects($userId, $tenantId);
        $tasks    = $this->tasks($userId, $tenantId);
        $tickets  = $this->tickets($userId, $tenantId);

        return [
            'employee_id'    => $employee->id,
            'linked'         => true,
            'user_id'        => $userId,
            'reason'         => null,
            'projects'       => $projects,
            'tasks'          => $tasks,
            'tickets'        => $tickets,
            'knowledge_base' => $this->knowledgeBase($employee, $tenantId),
            // Dated references for the profile Timeline, merged with the HR audit
            // trail there. Built from the rows already fetched above — no extra
            // queries, and no fifth copy of the data.
            'activity'       => $this->activity($projects, $tasks, $tickets),
        ];
    }

    /**
     * The lifecycle rows as dated activity references.
     *
     * These are REFERENCES, not events: the modules record no per-item event
     * stream this service can read, so the honest thing to show is "this task is
     * due on…", "this ticket was resolved on…", each linking to the record. Making
     * up an event history from a due date would be inventing a story.
     *
     * Undated rows are dropped rather than dated "now" — a task with no date has
     * no place on a timeline, and putting it at today would misrepresent it.
     */
    private function activity(array $projects, array $tasks, array $tickets): array
    {
        $entries = [];

        foreach ($projects['items'] as $p) {
            if ($p['due']) {
                $entries[] = ['date' => $p['due'], 'type' => 'project', 'title' => $p['title'],
                              'detail' => 'Project deadline', 'link' => $p['link']];
            }
        }

        foreach ($tasks['items'] as $t) {
            $date = $t['due'] ?? null;
            if ($date) {
                $entries[] = ['date' => $date, 'type' => 'task', 'title' => $t['title'],
                              'detail' => $t['done'] ? 'Task completed' : 'Task due', 'link' => $t['link']];
            }
        }

        foreach ($tickets['items'] as $t) {
            $date = $t['due'] ?? null;
            if ($date) {
                $entries[] = ['date' => $date, 'type' => 'ticket', 'title' => $t['title'],
                              'detail' => $t['resolved'] ? 'Ticket resolved' : 'Ticket due', 'link' => $t['link']];
            }
        }

        // Newest first, matching how the HR audit timeline already reads.
        usort($entries, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return array_slice($entries, 0, 20);
    }

    /* ── Sections ─────────────────────────────────────────────────────── */

    /** Projects the employee is a member of. Reuses project_members. */
    private function projects(int $userId, int $tenantId): array
    {
        $query = Project::where('tenant_id', $tenantId)
            ->whereHas('members', fn ($m) => $m->where('user_id', $userId));

        $rows = (clone $query)->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'name', 'status', 'deadline', 'progress']);

        return [
            'total' => (clone $query)->count(),
            'open'  => (clone $query)->whereNotIn('status', ['Finished', 'Cancelled'])->count(),
            'items' => $rows->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->name, 'status' => $p->status,
                'due' => optional($p->deadline)->toDateString(),
                'progress' => $p->progress,
                // The jump link the comment asks for.
                'link' => "/app/projects/{$p->id}",
            ])->all(),
            'link' => '/app/projects',
        ];
    }

    /** Tasks assigned to the employee. Reuses task_assignees. */
    private function tasks(int $userId, int $tenantId): array
    {
        $query = Task::where('tenant_id', $tenantId)
            ->whereHas('assignees', fn ($a) => $a->where('user_id', $userId));

        $rows = (clone $query)->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'name', 'status', 'priority', 'due_date', 'date_finished']);

        // "Done" is `date_finished`, not a status value. `tasks.status` holds a
        // free-form slug (in_progress, not_started, …) whose complete vocabulary is
        // not fixed anywhere, so matching on it would silently miscount the moment
        // a tenant added a status.
        return [
            'total'   => (clone $query)->count(),
            'open'    => (clone $query)->whereNull('date_finished')->count(),
            'overdue' => (clone $query)->whereNull('date_finished')
                ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
            'items' => $rows->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->name, 'status' => $t->status,
                'priority' => $t->priority,
                'due' => optional($t->due_date)->toDateString(),
                'done' => $t->date_finished !== null,
                // /app/tasks/{id} is the task detail route. ?task= was read by
                // nothing, so every link opened the whole board instead.
                'link' => "/app/tasks/{$t->id}",
            ])->all(),
            'link' => '/app/tasks',
        ];
    }

    /** Helpdesk tickets assigned to the employee. Reuses tickets.assigned_to. */
    private function tickets(int $userId, int $tenantId): array
    {
        $query = Ticket::where('tenant_id', $tenantId)->where('assigned_to', $userId);

        $rows = (clone $query)->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'subject', 'status', 'priority', 'due_date', 'resolved_at']);

        return [
            'total' => (clone $query)->count(),
            'open'  => (clone $query)->whereNull('resolved_at')->count(),
            'items' => $rows->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->subject,
                'status' => $t->status, 'priority' => $t->priority,
                'due' => optional($t->due_date)->toDateString(),
                'resolved' => $t->resolved_at !== null,
                'link' => "/app/helpdesk/tickets/{$t->id}",
            ])->all(),
            'link' => '/app/helpdesk/tickets',
        ];
    }

    /**
     * Knowledge Base articles for the employee's department.
     *
     * NOT "articles they wrote" — kb_articles records no author, so authorship is
     * unanswerable. The label says department so nobody reads it as ownership.
     */
    private function knowledgeBase(HrEmployee $employee, int $tenantId): array
    {
        $query = KbArticle::where('tenant_id', $tenantId)->where('is_published', true);

        // `kb_articles.department_id` is a foreign key to TICKET_DEPARTMENTS, not to
        // hr_departments — the helpdesk keeps its own department list. The two id
        // spaces are unrelated, so matching `hr_employees.department_id` against it
        // directly would return whichever helpdesk department happened to share a
        // number. The only meaningful bridge is the NAME.
        $ticketDepartmentId = $employee->department
            ? DB::table('ticket_departments')->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($employee->department))])
                ->value('id')
            : null;

        if ($ticketDepartmentId) {
            $query->where('department_id', $ticketDepartmentId);
            $basis = 'Published articles for the “'.$employee->department.'” helpdesk department, matched by name. '
                     .'kb_articles records no author, so this is not a list of what this employee wrote.';
        } else {
            // No matching helpdesk department: show the tenant's published articles
            // rather than nothing, and say why they are not narrowed.
            $basis = $employee->department
                ? 'No helpdesk department is named “'.$employee->department.'”, so all published articles are shown.'
                : 'No department on the employee record, so all published articles are shown.';
        }

        $rows = (clone $query)->orderByDesc('published_at')->limit(self::LIMIT)
            ->get(['id', 'title', 'published_at']);

        return [
            'total' => (clone $query)->count(),
            'items' => $rows->map(fn ($a) => [
                'id' => $a->id, 'title' => $a->title,
                'published_at' => optional($a->published_at)->toDateString(),
                // ?article= was read by nothing, so every link opened the KB
                // home page. The article route already exists.
                'link' => "/app/helpdesk/knowledge-base/{$a->id}",
            ])->all(),
            'link'  => '/app/helpdesk/knowledge-base',
            'basis' => $basis,
        ];
    }

    private function empty(): array
    {
        return ['total' => 0, 'items' => [], 'link' => null];
    }
}
