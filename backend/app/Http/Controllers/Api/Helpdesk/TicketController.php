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
        $this->helpdesk->assertTicketVisible($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role, $request->user()->email);
    }

    /** 403s anyone but an admin / department manager. */
    private function guardManage(Request $request, int $ticket, string $action = 'delete this ticket'): void
    {
        $this->helpdesk->assertTicketManage($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role, $action);
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
        $tickets = $this->helpdesk->listTickets($request->user()->tenant_id, $filters, $request->user()->id, $request->user()->role, $request->user()->email);

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

    /**
     * Fields that decide who owns the work, how fast it must be done, and who
     * gets to see it. Routing the ticket to another department also rewrites who
     * counts as its manager — so these are manager decisions, not edits.
     */
    private const MANAGEMENT_FIELDS = ['priority', 'assigned_to', 'department_id', 'customer_id', 'due_date'];

    /* ── Update ────────────────────────────────────────────────── */
    public function update(UpdateTicketRequest $request, int $ticket)
    {
        $this->guardView($request, $ticket);

        // Any agent who can see a ticket may correct its subject or description.
        // Re-prioritising, reassigning or re-routing it is a different act, and
        // it used to ride in on the same view-level guard.
        $data = $request->validated();
        if ($touched = array_intersect_key($data, array_flip(self::MANAGEMENT_FIELDS))) {
            $this->guardManage($request, $ticket, 'change '.implode(', ', array_keys($touched)));
        }

        $result = $this->helpdesk->updateTicket($ticket, $data, $request->user()->tenant_id);

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
        $data = $request->validate(['merge_ticket_id' => 'required|integer']);

        // Merging is delete's twin: the absorbed ticket is closed for good and
        // its replies move away. It was gated at view level while delete was
        // gated at manager level, so the weaker route reached the same end.
        // Both tickets are guarded — the one that dies matters more than the one
        // that survives, and guarding only the survivor would still let an agent
        // destroy a ticket they have no say over.
        $this->guardManage($request, $ticket, 'merge tickets');
        $this->guardManage($request, (int) $data['merge_ticket_id'], 'merge tickets');

        $survivor = $this->helpdesk->mergeTicket($ticket, $data['merge_ticket_id'], $request->user()->tenant_id);

        return $this->success($survivor, 'Ticket merged');
    }

    /* ── Assign agent (TicketAssignmentService) ────────────────── */
    public function assign(AssignTicketRequest $request, int $ticket)
    {
        // (Re)assignment is a manager act — admin, global/department manager — with
        // one addition: the CURRENT assignee can hand their own ticket on. Anyone
        // else who can merely SEE the ticket is 403'd here.
        $this->helpdesk->assertCanAssign($ticket, $request->user()->tenant_id, $request->user()->id, $request->user()->role);
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

    /* ── REQ-05: mark this ticket seen by the current user ─────── */
    public function seen(Request $request, int $ticket)
    {
        $this->guardView($request, $ticket);
        $this->helpdesk->markSeen($ticket, $request->user()->tenant_id, $request->user()->id);

        return $this->success(null, 'Ticket marked seen');
    }

    /* ── REQ-04-lite: count of visible tickets not yet seen ────── */
    public function unseenCount(Request $request)
    {
        $count = $this->helpdesk->countUnseen(
            $request->user()->tenant_id,
            $request->user()->id,
            $request->user()->role,
            $request->user()->email,
        );

        return $this->success(['count' => $count], 'Unseen count retrieved');
    }

    /* ── Sidebar status counts (open / in-progress / closed) ───── */
    public function statusCounts(Request $request)
    {
        $counts = $this->helpdesk->statusCounts(
            $request->user()->tenant_id,
            $request->user()->id,
            $request->user()->role,
            $request->user()->email,
        );

        return $this->success($counts, 'Status counts retrieved');
    }

    /* ── Bulk actions: ONE request for the whole selection ─────────
     *
     * The grid used to fire one HTTP call PER selected ticket, in parallel. On the
     * single-threaded dev server those serialize, and any that closed a ticket
     * blocked on a synchronous email — so twenty tickets took most of a minute and
     * a failure half-way left the selection partly applied with no word of why.
     *
     * This runs the whole batch in one request, guarding each ticket with the SAME
     * rules as the single-ticket routes (view for status, assign-rights for assign,
     * manage for delete), and reports exactly which ids were skipped and why so the
     * grid can surface it instead of looking like nothing happened.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:status,assign,delete'],
            'ids'    => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'  => ['integer'],
            'value'  => ['nullable'],
        ]);

        $u = $request->user();
        $tenantId = $u->tenant_id;

        // Validate a status value once, up front, against the tenant's real statuses
        // — a bad value should fail the whole call, not be retried per ticket.
        if ($data['action'] === 'status') {
            $allowed = app(\App\Services\Helpdesk\HelpdeskSettingsService::class)->statusNames($tenantId);
            if (! in_array($data['value'] ?? null, $allowed, true)) {
                throw new \App\Exceptions\BusinessException('That status is not valid for this workspace.', 422);
            }
        }

        $ok = [];
        $failed = [];

        foreach (array_values(array_unique($data['ids'])) as $rawId) {
            $id = (int) $rawId;
            try {
                switch ($data['action']) {
                    case 'status':
                        $this->guardView($request, $id);
                        $this->helpdesk->changeStatus($id, $data['value'], $tenantId);
                        break;
                    case 'assign':
                        $this->helpdesk->assertCanAssign($id, $tenantId, $u->id, $u->role);
                        $assignee = ($data['value'] ?? '') !== '' ? (int) $data['value'] : null;
                        $this->assignment->assign($id, $assignee, $tenantId);
                        break;
                    case 'delete':
                        $this->guardManage($request, $id);
                        $this->helpdesk->deleteTicket($id, $tenantId);
                        break;
                }
                $ok[] = $id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        $n = count($ok);
        $msg = $n.' ticket'.($n === 1 ? '' : 's').' updated'
            .(count($failed) ? ', '.count($failed).' skipped' : '');

        return $this->success(['ok' => $ok, 'failed' => $failed], $msg);
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
