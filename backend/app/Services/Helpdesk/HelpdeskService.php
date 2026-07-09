<?php

namespace App\Services\Helpdesk;

use App\Events\Helpdesk\TicketClosed;
use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketAttachment;
use App\Models\Helpdesk\TicketFeedback;
use App\Models\Helpdesk\TicketReply;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HelpdeskService
{
    private CustomerServiceContract $customers;

    /**
     * The customer dependency is a service contract (rule 2). Laravel injects the
     * bound implementation if one exists; until Zafar binds his real service we
     * fall back to the mock, so this class works with zero global wiring.
     */
    public function __construct(?CustomerServiceContract $customers = null)
    {
        $this->customers = $customers ?? new MockCustomerService();
    }

    /* ── Tickets: read ──────────────────────────────────────────── */

    public function listTickets(int $tenantId, array $filters = []): Collection
    {
        return Ticket::forTenant($tenantId)
            ->status($filters['status'] ?? null)
            ->priority($filters['priority'] ?? null)
            ->when(
                ! empty($filters['assigned_to']),
                fn ($q) => $q->where('assigned_to', $filters['assigned_to'])
            )
            ->when(
                ! empty($filters['customer_id']),
                fn ($q) => $q->where('customer_id', $filters['customer_id'])
            )
            ->when(
                ! empty($filters['search']),
                fn ($q) => $q->where(function ($sub) use ($filters) {
                    $sub->where('subject', 'like', '%'.$filters['search'].'%')
                        ->orWhere('description', 'like', '%'.$filters['search'].'%');
                })
            )
            ->with('assignee:id,name,email')
            ->latest()
            ->get()
            ->map(fn (Ticket $t) => $this->decorateWithCustomer($t, $tenantId));
    }

    public function showTicket(int $ticketId, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);
        $ticket->load(['assignee:id,name,email', 'replies.attachments', 'feedback']);

        return $this->decorateWithCustomer($ticket, $tenantId);
    }

    /* ── Tickets: write ─────────────────────────────────────────── */

    public function createTicket(array $data, int $tenantId): Ticket
    {
        // Validate the cross-module customer link through the contract, not the DB.
        if (! empty($data['customer_id']) && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $ticket = Ticket::create([
            'tenant_id'   => $tenantId,
            'subject'     => $data['subject'],
            'description' => $data['description'] ?? null,
            'status'      => $data['status'] ?? 'open',
            'priority'    => $data['priority'] ?? 'medium',
            'assigned_to' => $data['assigned_to'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'deadline'    => $data['deadline'] ?? null,
        ]);

        return $this->decorateWithCustomer($ticket->fresh('assignee'), $tenantId);
    }

    public function updateTicket(int $ticketId, array $data, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id'])
            && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $ticket->fill(array_intersect_key($data, array_flip([
            'subject', 'description', 'status', 'priority',
            'assigned_to', 'customer_id', 'deadline',
        ])));
        $ticket->save();

        return $this->decorateWithCustomer($ticket->fresh('assignee'), $tenantId);
    }

    public function changeStatus(int $ticketId, string $status, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);
        $was = $ticket->status;
        $ticket->update(['status' => $status]);

        // Fire the closure event exactly once, on the open→closed transition.
        // The listener emails the customer a one-click feedback request.
        if ($status === 'closed' && $was !== 'closed') {
            TicketClosed::dispatch($ticket->fresh());
        }

        return $ticket->fresh('assignee');
    }

    public function assign(int $ticketId, ?int $userId, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);
        $ticket->update(['assigned_to' => $userId]);

        return $ticket->fresh('assignee');
    }

    public function deleteTicket(int $ticketId, int $tenantId): void
    {
        $this->findTicket($ticketId, $tenantId)->delete();
    }

    /* ── Replies + attachments ──────────────────────────────────── */

    /**
     * Add a reply to a ticket. Attachments are an array of
     * ['file_path' => ..., 'file_name' => ...] rows; when present they are
     * persisted and `has_attachments` is flipped — all in one transaction.
     */
    public function addReply(int $ticketId, array $data, int $tenantId): TicketReply
    {
        $ticket = $this->findTicket($ticketId, $tenantId);
        $attachments = $data['attachments'] ?? [];

        return DB::transaction(function () use ($ticket, $data, $attachments, $tenantId) {
            $reply = TicketReply::create([
                'tenant_id'       => $tenantId,
                'ticket_id'       => $ticket->id,
                'sender_type'     => $data['sender_type'],
                'sender_id'       => $data['sender_id'] ?? null,
                'message'         => $data['message'],
                'has_attachments' => count($attachments) > 0,
            ]);

            foreach ($attachments as $file) {
                $reply->attachments()->create([
                    'tenant_id' => $tenantId,
                    'file_path' => $file['file_path'],
                    'file_name' => $file['file_name'],
                ]);
            }

            // Thread automations:
            //  • a customer reply to a CLOSED ticket auto-reopens it;
            //  • a staff reply to an OPEN ticket moves it into progress.
            if ($data['sender_type'] === 'client' && $ticket->status === 'closed') {
                $ticket->update(['status' => 'open']);
            } elseif ($data['sender_type'] !== 'client' && $ticket->status === 'open') {
                $ticket->update(['status' => 'in-progress']);
            }

            return $reply->load('attachments');
        });
    }

    public function listReplies(int $ticketId, int $tenantId): Collection
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        return $ticket->replies()->with('attachments')->get();
    }

    /* ── Analytics (manager dashboard) ──────────────────────────── */

    /**
     * Aggregate metrics for the Helpdesk manager dashboard. All figures are
     * scoped to the tenant. "Closing time" is measured from a ticket's creation
     * to its last update (we treat the final update on a closed ticket as its
     * resolution time, since there is no dedicated resolved_at column yet).
     */
    public function analytics(int $tenantId): array
    {
        $base = fn () => Ticket::forTenant($tenantId);

        $total       = $base()->count();
        $open        = $base()->where('status', 'open')->count();
        $inProgress  = $base()->where('status', 'in-progress')->count();
        $closed      = $base()->where('status', 'closed')->count();
        $unresolved  = $open + $inProgress;

        // Open rate = share of tickets not yet closed.
        $openRate = $total > 0 ? round(($unresolved / $total) * 100, 1) : 0.0;

        // Average closing time (hours) across closed tickets.
        $closedTickets = $base()->where('status', 'closed')->get(['created_at', 'updated_at']);
        $avgClosingHours = $closedTickets->count() > 0
            ? round($closedTickets->avg(fn (Ticket $t) => $t->created_at->diffInMinutes($t->updated_at)) / 60, 1)
            : 0.0;

        // Tickets resolved (closed) per assignee.
        $byAssignee = $base()->where('status', 'closed')
            ->selectRaw('assigned_to, count(*) as resolved')
            ->groupBy('assigned_to')
            ->with('assignee:id,name')
            ->get()
            ->map(fn (Ticket $t) => [
                'assignee_id' => $t->assigned_to,
                'name'        => $t->assignee->name ?? 'Unassigned',
                'resolved'    => (int) $t->resolved,
            ])
            ->sortByDesc('resolved')
            ->values();

        return [
            'total'             => $total,
            'open'              => $open,
            'in_progress'       => $inProgress,
            'closed'            => $closed,
            'unresolved'        => $unresolved,
            'open_rate'         => $openRate,
            'avg_closing_hours' => $avgClosingHours,
            'by_status'         => [
                ['status' => 'open',        'count' => $open],
                ['status' => 'in-progress', 'count' => $inProgress],
                ['status' => 'closed',      'count' => $closed],
            ],
            'by_priority' => [
                ['priority' => 'high',   'count' => $base()->where('priority', 'high')->count()],
                ['priority' => 'medium', 'count' => $base()->where('priority', 'medium')->count()],
                ['priority' => 'low',    'count' => $base()->where('priority', 'low')->count()],
            ],
            'resolved_by_assignee' => $byAssignee,
        ];
    }

    /* ── Feedback (CSAT) ────────────────────────────────────────── */

    public function submitFeedback(int $ticketId, array $data, int $tenantId): TicketFeedback
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if ($ticket->status !== 'closed') {
            throw new BusinessException('Feedback can only be given on a closed ticket.', 422);
        }

        // One feedback row per ticket — upsert so re-submission overwrites.
        return TicketFeedback::updateOrCreate(
            ['tenant_id' => $tenantId, 'ticket_id' => $ticket->id],
            ['rating' => $data['rating'], 'comments' => $data['comments'] ?? null],
        );
    }

    /**
     * One-click feedback from the closure email. There is no authenticated user
     * here — the signed URL in the email is the authorization — so the ticket is
     * looked up globally and its own tenant_id is used for the feedback row.
     */
    public function submitFeedbackOneClick(int $ticketId, int $rating): TicketFeedback
    {
        $ticket = Ticket::find($ticketId);

        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        $rating = max(1, min(5, $rating));   // clamp to 1–5

        return TicketFeedback::updateOrCreate(
            ['tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id],
            ['rating' => $rating],
        );
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /** Resolve an attachment, scoped to both its ticket and the tenant. */
    public function findAttachment(int $attachmentId, int $ticketId, int $tenantId): TicketAttachment
    {
        $attachment = TicketAttachment::forTenant($tenantId)
            ->whereHas('reply', fn ($q) => $q->where('ticket_id', $ticketId))
            ->find($attachmentId);

        if (! $attachment) {
            throw new BusinessException('Attachment not found.', 404);
        }

        return $attachment;
    }

    private function findTicket(int $ticketId, int $tenantId): Ticket
    {
        $ticket = Ticket::forTenant($tenantId)->find($ticketId);

        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        return $ticket;
    }

    /**
     * Attach resolved customer data (from the contract) onto the ticket as a
     * non-persisted attribute, so API responses can show the customer without
     * Helpdesk ever joining Zafar's table.
     */
    private function decorateWithCustomer(Ticket $ticket, int $tenantId): Ticket
    {
        $ticket->setAttribute(
            'customer',
            $ticket->customer_id ? $this->customers->getCustomer((int) $ticket->customer_id, $tenantId) : null
        );

        return $ticket;
    }
}
