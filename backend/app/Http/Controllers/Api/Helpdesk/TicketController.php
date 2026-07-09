<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    /* ── List ──────────────────────────────────────────────────── */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'priority', 'assigned_to', 'customer_id', 'search']);
        $tickets = $this->helpdesk->listTickets($request->user()->tenant_id, $filters);

        return $this->success($tickets, 'Tickets retrieved');
    }

    /* ── Create ────────────────────────────────────────────────── */
    public function store(Request $request)
    {
        $data = $request->validate([
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['nullable', 'in:open,in-progress,closed'],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'deadline'    => ['nullable', 'date'],
        ]);

        $ticket = $this->helpdesk->createTicket($data, $request->user()->tenant_id);

        return $this->success($ticket, 'Ticket created', 201);
    }

    /* ── Show ──────────────────────────────────────────────────── */
    public function show(Request $request, int $ticket)
    {
        $result = $this->helpdesk->showTicket($ticket, $request->user()->tenant_id);

        return $this->success($result, 'Ticket retrieved');
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(Request $request, int $ticket)
    {
        $data = $request->validate([
            'subject'     => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'in:open,in-progress,closed'],
            'priority'    => ['sometimes', 'in:low,medium,high'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'deadline'    => ['nullable', 'date'],
        ]);

        $result = $this->helpdesk->updateTicket($ticket, $data, $request->user()->tenant_id);

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
        $data = $request->validate([
            'status' => ['required', 'in:open,in-progress,closed'],
        ]);

        $result = $this->helpdesk->changeStatus($ticket, $data['status'], $request->user()->tenant_id);

        return $this->success($result, 'Status updated');
    }

    /* ── Assign agent ──────────────────────────────────────────── */
    public function assign(Request $request, int $ticket)
    {
        $data = $request->validate([
            'assigned_to' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $result = $this->helpdesk->assign($ticket, $data['assigned_to'], $request->user()->tenant_id);

        return $this->success($result, 'Ticket assigned');
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
