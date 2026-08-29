<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Project\ProjectExpense;
use App\Models\Task\Task;
use App\Services\StatusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A vendor / third-party-vendor's own work: the projects raised for them, the
 * tasks assigned to them, and the tickets assigned to them.
 *
 * Deliberately NOT behind the `vendor.portal` middleware — that requires a
 * vendor-master profile row, whereas "my assigned work" is about the logged-in
 * User (roles vendor / third_party_vendor). Everything is filtered to
 * `$request->user()` and the caller's tenant; no vendor id is ever named in a
 * URL, so there is nothing to forge and no cross-vendor access.
 */
class VendorWorkController extends Controller
{
    use ApiResponse;

    /**
     * The caller's vendor-master record id. Covers all three ways a login maps to
     * a vendor: the vendor's PRIMARY account (vendors.user_id), the same email, or
     * an EMPLOYEE login (vendor_contacts.user_id). An employee sees their vendor's
     * record-linked work just like the primary account does. A login-less vendor's
     * record work is reached by the email fallback inside the service.
     */
    protected function ownVendorId(Request $request): ?int
    {
        return app(\App\Services\Vendor\VendorEmployeeService::class)
            ->resolveVendorIdForUser($request->user());
    }

    /**
     * Projects reach a vendor two ways: their login User is the `vendor_user_id`,
     * or the project is linked to the vendor RECORD (`vendor_id` + a tpv link_type).
     * The second path covers login-less vendors.
     */
    protected function scopeVendorProjects($query, Request $request)
    {
        $uid = $request->user()->id;
        $vendorId = $this->ownVendorId($request);

        return $query->where(function ($q) use ($uid, $vendorId) {
            $q->where('vendor_user_id', $uid);
            if ($vendorId) {
                $q->orWhere(fn ($r) => $r->whereIn('link_type', ['tpv', 'tpv_vendor'])->where('vendor_id', $vendorId));
            }
        });
    }

    /** Headline counts for the portal dashboard cards. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
        $uid = $user->id;

        $tasks = Task::forTenant($tid)->whereHas('assignees', fn ($q) => $q->where('user_id', $uid));

        return $this->success([
            'projects'   => $this->scopeVendorProjects(Project::where('tenant_id', $tid), $request)->count(),
            'tasks'      => (clone $tasks)->count(),
            'open_tasks' => (clone $tasks)->whereNotIn('status', ['complete', 'finished', 'cancelled'])->count(),
            'tickets'    => Ticket::forTenant($tid)->where('assigned_to', $uid)->count(),
        ], 'Work summary retrieved');
    }

    /** Projects raised for this vendor / TPV. */
    public function projects(Request $request)
    {
        $user = $request->user();

        $rows = $this->scopeVendorProjects(Project::where('tenant_id', $user->tenant_id), $request)
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'progress', 'link_type', 'deadline', 'created_at'])
            ->map(fn (Project $p) => [
                'id'       => $p->id,
                'name'     => $p->name,
                'status'   => $p->status,
                'progress' => (int) $p->progress,
                'role'     => $p->link_type === 'tpv' ? 'Third-party vendor' : 'Vendor',
                'deadline' => optional($p->deadline)->toDateString(),
            ]);

        return $this->success($rows, 'Projects retrieved');
    }

    /** Tasks assigned to this vendor / TPV, newest first. */
    public function tasks(Request $request)
    {
        $user = $request->user();

        // Two ways a task reaches a TPV: the vendor's User is an assignee, or the
        // task is linked to the vendor RECORD via rel_type='tpv_vendor'. Before the
        // rel link existed only the first was possible, so a task raised against the
        // vendor as an organisation never appeared here. Resolution covers primary
        // AND employee logins, so a vendor's employees see the vendor's tasks too.
        $vendorId = $this->ownVendorId($request);

        $tasks = Task::forTenant($user->tenant_id)
            ->where(function ($q) use ($user, $vendorId) {
                $q->whereHas('assignees', fn ($a) => $a->where('user_id', $user->id));
                if ($vendorId) {
                    $q->orWhere(fn ($r) => $r->where('rel_type', 'tpv_vendor')->where('rel_id', $vendorId));
                }
            })
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'priority', 'due_date', 'rel_type', 'rel_id']);

        // Resolve the parent project name for project-linked tasks in one query.
        $projectIds = $tasks->where('rel_type', 'project')->pluck('rel_id')->filter()->unique();
        $projectNames = $projectIds->isEmpty()
            ? collect()
            : Project::where('tenant_id', $user->tenant_id)->whereIn('id', $projectIds)->pluck('name', 'id');

        $rows = $tasks->map(fn (Task $t) => [
            'id'       => $t->id,
            'name'     => $t->name,
            'status'   => $t->status,
            'priority' => $t->priority,
            'due_date' => optional($t->due_date)->toDateString(),
            'project'  => $t->rel_type === 'project' ? ($projectNames[$t->rel_id] ?? null) : null,
        ]);

        return $this->success($rows, 'Tasks retrieved');
    }

    /** Tickets assigned to this vendor / TPV. */
    public function tickets(Request $request)
    {
        $user = $request->user();

        // Tickets assigned to the vendor OR raised by the vendor from the portal.
        $rows = Ticket::forTenant($user->tenant_id)
            ->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id))
            ->orderByDesc('id')
            ->get(['id', 'subject', 'status', 'priority', 'created_at'])
            ->map(fn (Ticket $t) => [
                'id'       => $t->id,
                'subject'  => $t->subject,
                'status'   => $t->status,
                'priority' => $t->priority,
            ]);

        return $this->success($rows, 'Tickets retrieved');
    }

    /** The vendor raises a support ticket from the portal (lands in the open queue). */
    public function raiseTicket(Request $request, \App\Services\Helpdesk\HelpdeskService $helpdesk)
    {
        $user = $request->user();
        $data = $request->validate([
            'subject'  => 'required|string|max:191',
            'body'     => 'required|string|max:10000',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $ticket = $helpdesk->createTicket([
            'subject'         => $data['subject'],
            'description'     => $data['body'],
            'created_by'      => $user->id,
            'requester_name'  => $user->name,
            'requester_email' => $user->email,
            'source'          => 'portal',
            'assigned_to'     => null,
            'priority'        => $data['priority'] ?? 'medium',
        ], (int) $user->tenant_id);

        return response()->json(['id' => $ticket->id, 'message' => 'Your ticket has been raised.'], 201);
    }

    /** One of the vendor's own tickets, with its reply thread. */
    public function ticket(Request $request, Ticket $ticket)
    {
        $this->assertOwnTicket($request, $ticket);

        $replies = $ticket->replies()->orderBy('id')->get(['id', 'sender_type', 'message', 'created_at'])
            ->map(fn ($r) => [
                'id'      => $r->id,
                'mine'    => $r->sender_type === 'client',
                'author'  => $r->sender_type === 'client' ? 'You' : 'Support',
                'message' => $r->message,
                'at'      => optional($r->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'id' => $ticket->id, 'subject' => $ticket->subject, 'status' => $ticket->status,
            'priority' => $ticket->priority, 'description' => $ticket->description,
            'replies' => $replies,
        ]);
    }

    /** The vendor posts a reply on its own ticket. */
    public function replyTicket(Request $request, Ticket $ticket, \App\Services\Helpdesk\HelpdeskService $helpdesk)
    {
        $this->assertOwnTicket($request, $ticket);
        $data = $request->validate(['message' => 'required|string|max:10000']);

        $helpdesk->addReply($ticket->id, [
            'sender_type' => 'client',   // external requester (shown as the vendor)
            'sender_id'   => null,
            'message'     => $data['message'],
        ], (int) $request->user()->tenant_id);

        return response()->json(['message' => 'Reply sent'], 201);
    }

    private function assertOwnTicket(Request $request, Ticket $ticket): void
    {
        $uid = (int) $request->user()->id;
        $owns = (int) $ticket->assigned_to === $uid || (int) $ticket->created_by === $uid;
        abort_unless($owns && (int) $ticket->tenant_id === (int) $request->user()->tenant_id, 404, 'Ticket not found');
    }

    /* ── Vendor writes ───────────────────────────────────────────────────── */

    /** The tenant's task-status options (key → label) for the portal's status picker. */
    public function taskStatuses(Request $request)
    {
        return $this->success(app(StatusService::class)->labels('task', (int) $request->user()->tenant_id), 'Statuses retrieved');
    }

    /** The vendor advances the status of one of its OWN tasks (tenant status set). */
    public function updateTaskStatus(Request $request, Task $task)
    {
        $this->assertOwnTask($request, $task);

        $keys = app(StatusService::class)->keys('task', (int) $request->user()->tenant_id);
        $data = $request->validate(['status' => ['required', Rule::in($keys)]]);

        $task->update(['status' => $data['status']]);

        return $this->success(['id' => $task->id, 'status' => $task->status], 'Task updated');
    }

    /** A flat list of expenses logged against the vendor's own projects. */
    public function expenses(Request $request)
    {
        $projectIds = $this->scopeVendorProjects(Project::where('tenant_id', $request->user()->tenant_id), $request)->pluck('id');

        $rows = ProjectExpense::whereIn('project_id', $projectIds)
            ->latest('expense_date')
            ->get(['id', 'project_id', 'title', 'category', 'amount', 'expense_date', 'note', 'billable']);

        return $this->success($rows, 'Expenses retrieved');
    }

    /** The vendor logs an expense against a project it is on (visible to admin). */
    public function storeExpense(Request $request)
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'title'        => 'required|string|max:200',
            'category'     => 'nullable|string|max:100',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'nullable|date',
            'note'         => 'nullable|string|max:1000',
        ]);

        // The project must be one of the caller's own — else 404 (existence-hiding).
        $ownProjectIds = $this->scopeVendorProjects(Project::where('tenant_id', $request->user()->tenant_id), $request)->pluck('id')->all();
        abort_unless(in_array((int) $data['project_id'], array_map('intval', $ownProjectIds), true), 404, 'Project not found');

        $expense = ProjectExpense::create([
            'tenant_id'    => $request->user()->tenant_id,
            'project_id'   => $data['project_id'],
            'title'        => $data['title'],
            'category'     => $data['category'] ?? null,
            'amount'       => $data['amount'],
            'expense_date' => $data['expense_date'] ?? now()->toDateString(),
            'note'         => $data['note'] ?? null,
            'billable'     => true,
            'created_by'   => $request->user()->id,
        ]);

        return response()->json($expense, 201);
    }

    /** 404 unless the task is the caller's (assignee) or its vendor's (rel link). */
    private function assertOwnTask(Request $request, Task $task): void
    {
        $user = $request->user();
        $vendorId = $this->ownVendorId($request);

        $owns = $task->assignees()->where('user_id', $user->id)->exists()
            || ($vendorId && $task->rel_type === 'tpv_vendor' && (int) $task->rel_id === (int) $vendorId);

        abort_unless($owns && (int) $task->tenant_id === (int) $user->tenant_id, 404, 'Task not found');
    }
}
