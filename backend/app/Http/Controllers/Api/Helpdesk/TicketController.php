<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Helpdesk\AssignTicketRequest;
use App\Http\Requests\Helpdesk\StoreTicketRequest;
use App\Http\Requests\Helpdesk\UpdateTicketRequest;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\Helpdesk\TicketAssignmentService;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    use ApiResponse;

    public function __construct(
        private HelpdeskService $helpdesk,
        private TicketAssignmentService $assignment,
        private TaskService $tasks,
    ) {
    }

    /* ── List ──────────────────────────────────────────────────── */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'priority', 'assigned_to', 'customer_id', 'source', 'search']);
        $tickets = $this->helpdesk->listTickets($request->user()->tenant_id, $filters);

        return $this->success($tickets, 'Tickets retrieved');
    }

    /* ── My assigned tasks (assignee dashboard) ────────────────── */
    public function myTasks(Request $request)
    {
        $tasks = $this->assignment->myTasks($request->user()->id, $request->user()->tenant_id);

        return $this->success($tasks, 'My tasks retrieved');
    }

    /* ── Create ────────────────────────────────────────────────── */
    public function store(StoreTicketRequest $request)
    {
        $ticket = $this->helpdesk->createTicket($request->validated(), $request->user()->tenant_id);

        return $this->success($ticket, 'Ticket created', 201);
    }

    /* ── Show ──────────────────────────────────────────────────── */
    public function show(Request $request, int $ticket)
    {
        return $this->success($this->helpdesk->showTicket($ticket, $request->user()->tenant_id), 'Ticket retrieved');
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(UpdateTicketRequest $request, int $ticket)
    {
        $result = $this->helpdesk->updateTicket($ticket, $request->validated(), $request->user()->tenant_id);

        return $this->success($result, 'Ticket updated');
    }

    /* ── Delete ────────────────────────────────────────────────── */
    public function destroy(Request $request, int $ticket)
    {
        $this->helpdesk->deleteTicket($ticket, $request->user()->tenant_id);

        return $this->success(null, 'Ticket deleted');
    }

    /* ── Change status ─────────────────────────────────────────── */
    public function updateStatus(Request $request, int $ticket)
    {
        $tenantId = $request->user()->tenant_id;
        $allowed = app(\App\Services\Helpdesk\HelpdeskSettingsService::class)->statusNames($tenantId);
        $data = $request->validate(['status' => ['required', \Illuminate\Validation\Rule::in($allowed)]]);
        $result = $this->helpdesk->changeStatus($ticket, $data['status'], $tenantId);

        return $this->success($result, 'Status updated');
    }

    /* ── Merge a duplicate ticket into this one (Phase 3) ──────── */
    public function merge(Request $request, int $ticket)
    {
        $data = $request->validate(['merge_ticket_id' => 'required|integer']);
        $survivor = $this->helpdesk->mergeTicket($ticket, $data['merge_ticket_id'], $request->user()->tenant_id);

        return $this->success($survivor, 'Ticket merged');
    }

    /* ── Assign agent (TicketAssignmentService) ────────────────── */
    public function assign(AssignTicketRequest $request, int $ticket)
    {
        $result = $this->assignment->assign($ticket, $request->validated('assigned_to'), $request->user()->tenant_id);

        return $this->success($result, 'Ticket assigned');
    }

    /* ── Integration 3a: link ticket to a Project ──────────────── */
    public function linkProject(Request $request, int $ticket)
    {
        $data = $request->validate(['project_id' => 'present|nullable|integer']);
        $result = $this->helpdesk->linkProject($ticket, $data['project_id'], $request->user()->tenant_id);

        return $this->success($result, 'Ticket linked to project');
    }

    /* ── Integration 3b: create a Task from this ticket ────────── */
    public function createTask(Request $request, int $ticket)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'priority'    => 'nullable|in:low,medium,high,urgent',
            'due_date'    => 'nullable|date',
        ]);

        $tenantId = $request->user()->tenant_id;
        // Ensure the ticket exists in this tenant before linking a task to it.
        $this->helpdesk->showTicket($ticket, $tenantId);

        $task = $this->tasks->create([
            'name'       => $data['name'],
            'priority'   => $data['priority'] ?? 'medium',
            'due_date'   => $data['due_date'] ?? null,
            'start_date' => now()->toDateString(),
            'rel_type'   => 'ticket',
            'rel_id'     => $ticket,
        ], $tenantId, $request->user()->id);

        if (! empty($data['assigned_to'])) {
            $this->tasks->syncAssignees($task->id, [$data['assigned_to']], $tenantId);
        }

        return $this->success($task, 'Task created from ticket', 201);
    }

    /* ── Phase 4: convert ONE ticket into MULTIPLE tasks ───────── */
    public function createTasks(Request $request, int $ticket)
    {
        $data = $request->validate([
            'tasks'               => 'required|array|min:1|max:20',
            'tasks.*.name'        => 'required|string|max:255',
            'tasks.*.assigned_to' => 'nullable|integer|exists:users,id',
            'tasks.*.priority'    => 'nullable|in:low,medium,high,urgent',
            'tasks.*.due_date'    => 'nullable|date',
        ]);

        $tenantId = $request->user()->tenant_id;
        $this->helpdesk->showTicket($ticket, $tenantId); // tenant guard

        $created = [];
        foreach ($data['tasks'] as $row) {
            $task = $this->tasks->create([
                'name'       => $row['name'],
                'priority'   => $row['priority'] ?? 'medium',
                'due_date'   => $row['due_date'] ?? null,
                'start_date' => now()->toDateString(),
                'rel_type'   => 'ticket',
                'rel_id'     => $ticket,
            ], $tenantId, $request->user()->id);

            if (! empty($row['assigned_to'])) {
                $this->tasks->syncAssignees($task->id, [$row['assigned_to']], $tenantId);
            }
            $created[] = $task;
        }

        return $this->success($created, count($created).' tasks created from ticket', 201);
    }

    /* ── Submit CSAT feedback ──────────────────────────────────── */
    public function feedback(Request $request, int $ticket)
    {
        $data = $request->validate([
            'rating'   => ['required', 'integer', 'between:1,5'],
            'comments' => ['nullable', 'string'],
        ]);
        $result = $this->helpdesk->submitFeedback($ticket, $data, $request->user()->tenant_id);

        return $this->success($result, 'Feedback recorded', 201);
    }
}
