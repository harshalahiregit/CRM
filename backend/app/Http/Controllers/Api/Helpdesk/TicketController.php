<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Helpdesk\AssignTicketRequest;
use App\Http\Requests\Helpdesk\StoreTicketRequest;
use App\Http\Requests\Helpdesk\UpdateTicketRequest;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\Helpdesk\TicketAssignmentService;
use App\Services\Helpdesk\TicketSummaryService;
use App\Services\Task\TaskService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    use ApiResponse;

    public function __construct(
        private HelpdeskService $helpdesk,
        private TicketAssignmentService $assignment,
        private TaskService $tasks,
        private TicketSummaryService $summaries,
    ) {
    }

    /** 403s an agent who tries to reach a ticket outside their queue. */
    private function guardView(Request $request, int $ticket): void
    {
        $this->helpdesk->assertTicketVisible($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role);
    }

    /** 403s anyone but an admin / department manager (used for delete). */
    private function guardManage(Request $request, int $ticket): void
    {
        $this->helpdesk->assertTicketManage($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role);
    }

    /* ── AI summary (Phase 6, cached; ?refresh=1 regenerates) ──── */
    public function summarize(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $force = $request->boolean('refresh');
        $t = $this->summaries->summarize($ticket, $request->user()->tenant_id, $force);

        return $this->success([
            'ai_summary'    => $t->ai_summary,
            'ai_summary_at' => $t->ai_summary_at,
            'has_provider'  => $this->summaries->hasProvider(),
        ], 'Summary generated');
    }

    /* ── List ──────────────────────────────────────────────────── */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'priority', 'assigned_to', 'customer_id', 'source', 'search']);
        $tickets = $this->helpdesk->listTickets($request->user()->tenant_id, $filters, $request->user()->id, $request->user()->role);

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
        $this->guardView($request, $ticket);
        return $this->success($this->helpdesk->showTicket($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role), 'Ticket retrieved');
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(UpdateTicketRequest $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $result = $this->helpdesk->updateTicket($ticket, $request->validated(), $request->user()->tenant_id);

        return $this->success($result, 'Ticket updated');
    }

    /* ── Delete (admin / department manager only) ──────────────── */
    public function destroy(Request $request, int $ticket)
    {
        $this->guardManage($request, $ticket);
        $this->helpdesk->deleteTicket($ticket, $request->user()->tenant_id);

        return $this->success(null, 'Ticket deleted');
    }

    /* ── Change status ─────────────────────────────────────────── */
    public function updateStatus(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $tenantId = $request->user()->tenant_id;
        $allowed = app(\App\Services\Helpdesk\HelpdeskSettingsService::class)->statusNames($tenantId);
        $data = $request->validate(['status' => ['required', \Illuminate\Validation\Rule::in($allowed)]]);
        $result = $this->helpdesk->changeStatus($ticket, $data['status'], $tenantId);

        return $this->success($result, 'Status updated');
    }

    /* ── Merge a duplicate ticket into this one (Phase 3) ──────── */
    public function merge(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $data = $request->validate(['merge_ticket_id' => 'required|integer']);
        $survivor = $this->helpdesk->mergeTicket($ticket, $data['merge_ticket_id'], $request->user()->tenant_id);

        return $this->success($survivor, 'Ticket merged');
    }

    /* ── Assign agent (TicketAssignmentService) ────────────────── */
    public function assign(AssignTicketRequest $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $result = $this->assignment->assign($ticket, $request->validated('assigned_to'), $request->user()->tenant_id);

        return $this->success($result, 'Ticket assigned');
    }

    /* ── Integration 3a: link ticket to a Project ──────────────── */
    public function linkProject(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
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

        $this->guardView($request, $ticket);
        $tenantId = $request->user()->tenant_id;

        // assignee_ids is handled inside TaskService::create, which notifies the
        // assignee — converting a ticket to a task must not assign work silently.
        $task = $this->tasks->create([
            'name'         => $data['name'],
            'priority'     => $data['priority'] ?? 'medium',
            'due_date'     => $data['due_date'] ?? null,
            'start_date'   => now()->toDateString(),
            'rel_type'     => 'ticket',
            'rel_id'       => $ticket,
            'assignee_ids' => array_filter([$data['assigned_to'] ?? null]),
        ], $tenantId, $request->user()->id);

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

        $this->guardView($request, $ticket);
        $tenantId = $request->user()->tenant_id;

        $created = [];
        foreach ($data['tasks'] as $row) {
            $created[] = $this->tasks->create([
                'name'         => $row['name'],
                'priority'     => $row['priority'] ?? 'medium',
                'due_date'     => $row['due_date'] ?? null,
                'start_date'   => now()->toDateString(),
                'rel_type'     => 'ticket',
                'rel_id'       => $ticket,
                'assignee_ids' => array_filter([$row['assigned_to'] ?? null]),
            ], $tenantId, $request->user()->id);
        }

        return $this->success($created, count($created).' tasks created from ticket', 201);
    }

    /* ── Submit CSAT feedback ──────────────────────────────────── */
    public function feedback(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $data = $request->validate([
            'rating'   => ['required', 'integer', 'between:1,5'],
            'comments' => ['nullable', 'string'],
        ]);
        $result = $this->helpdesk->submitFeedback($ticket, $data, $request->user()->tenant_id);

        return $this->success($result, 'Feedback recorded', 201);
    }
}
