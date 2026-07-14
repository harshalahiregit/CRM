<?php

namespace App\Services\Helpdesk;

use App\Events\Helpdesk\TicketClosed;
use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketAttachment;
use App\Models\Helpdesk\TicketFeedback;
use App\Models\Helpdesk\TicketReply;
use App\Repositories\Helpdesk\TicketRepository;
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
     * TicketRepository is auto-resolved (no dependencies of its own).
     */
    public function __construct(
        private TicketRepository $tickets,
        private SlaService $sla,
        private HelpdeskMailService $mail,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    /* ── Tickets: read ──────────────────────────────────────────── */

    public function listTickets(int $tenantId, array $filters = []): Collection
    {
        return $this->tickets->filtered($tenantId, $filters)
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

        $ticket = $this->tickets->create([
            'tenant_id'       => $tenantId,
            'subject'         => $data['subject'],
            'description'     => $data['description'] ?? null,
            'status'          => $data['status'] ?? 'open',
            'priority'        => $data['priority'] ?? 'medium',
            'assigned_to'     => $data['assigned_to'] ?? null,
            'customer_id'     => $data['customer_id'] ?? null,
            'due_date'        => $data['due_date'] ?? null,
            'source'          => $data['source'] ?? 'internal',
            'requester_name'  => $data['requester_name'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
        ]);

        // Acknowledge the requester with their ticket number (email threading on).
        $this->mail->sendAcknowledgement($ticket);

        return $this->decorateWithCustomer($ticket->fresh('assignee'), $tenantId);
    }

    public function updateTicket(int $ticketId, array $data, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id'])
            && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $oldStatus = $ticket->status;
        $ticket->fill(array_intersect_key($data, array_flip([
            'subject', 'description', 'status', 'priority',
            'assigned_to', 'customer_id', 'due_date',
        ])));
        $ticket->save();

        if (array_key_exists('status', $data) && $data['status'] !== $oldStatus) {
            $this->sla->onStatusChange($ticket, $oldStatus, $ticket->status);
        }

        return $this->decorateWithCustomer($ticket->fresh('assignee'), $tenantId);
    }

    /** Integration 3a: link (or unlink) a ticket to a Project in the same tenant. */
    public function linkProject(int $ticketId, ?int $projectId, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if ($projectId !== null
            && ! \App\Models\Project\Project::forTenant($tenantId)->whereKey($projectId)->exists()) {
            throw new BusinessException('Project not found.', 404);
        }

        $ticket->update(['project_id' => $projectId]);

        return $ticket->fresh();
    }

    public function changeStatus(int $ticketId, string $status, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);
        $was = $ticket->status;
        $ticket->update(['status' => $status]);
        $this->sla->onStatusChange($ticket, $was, $status);

        // Fire the closure event exactly once, on the open→closed transition.
        // The listener emails the customer a one-click feedback request.
        if ($status === 'closed' && $was !== 'closed') {
            TicketClosed::dispatch($ticket->fresh());
        }

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

        $reply = DB::transaction(function () use ($ticket, $data, $attachments, $tenantId) {
            $reply = TicketReply::create([
                'tenant_id'       => $tenantId,
                'ticket_id'       => $ticket->id,
                'sender_type'     => $data['sender_type'],
                'sender_id'       => $data['sender_id'] ?? null,
                'message'         => $data['message'],
                'cc'              => ! empty($data['cc']) ? $data['cc'] : null,
                'has_attachments' => count($attachments) > 0,
            ]);

            foreach ($attachments as $file) {
                $reply->attachments()->create([
                    'tenant_id' => $tenantId,
                    'file_path' => $file['file_path'],
                    'file_name' => $file['file_name'],
                ]);
            }

            // First staff reply stops the first-response SLA clock.
            if ($data['sender_type'] !== 'client') {
                $this->sla->onStaffReply($ticket);
            }

            // Thread automations:
            //  • a customer reply to a CLOSED ticket auto-reopens it;
            //  • a staff reply to an OPEN ticket moves it into progress.
            if ($data['sender_type'] === 'client' && $ticket->status === 'closed') {
                $ticket->update(['status' => 'open']);
                $this->sla->onStatusChange($ticket, 'closed', 'open');
            } elseif ($data['sender_type'] !== 'client' && $ticket->status === 'open') {
                $ticket->update(['status' => 'in-progress']);
                $this->sla->onStatusChange($ticket, 'open', 'in-progress');
            }

            return $reply->load('attachments');
        });

        // A staff reply is delivered to the customer as email (after the write
        // commits). Client-origin replies (portal/inbound) are not echoed back.
        if ($data['sender_type'] !== 'client') {
            $agentName = ! empty($data['sender_id'])
                ? (\App\Models\User::find($data['sender_id'])?->name ?? 'Support')
                : 'Support';
            $this->mail->sendStaffReply($ticket->fresh(), $reply, $agentName);
        }

        return $reply;
    }

    public function listReplies(int $ticketId, int $tenantId): Collection
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        $replies = $ticket->replies()->with('attachments')->get();

        // Resolve staff (admin/agent) sender names for display. sender_id is
        // polymorphic — for staff it points at the shared users table; for
        // clients it's a cross-module id we don't join. One lookup, no N+1.
        $staffIds = $replies->whereIn('sender_type', ['admin', 'agent'])
            ->pluck('sender_id')->filter()->unique();

        if ($staffIds->isNotEmpty()) {
            $names = \App\Models\User::whereIn('id', $staffIds)->pluck('name', 'id');
            $replies->each(function (TicketReply $r) use ($names) {
                if (in_array($r->sender_type, ['admin', 'agent'], true) && isset($names[$r->sender_id])) {
                    $r->setAttribute('sender', ['name' => $names[$r->sender_id]]);
                }
            });
        }

        return $replies;
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

        // Unresolved = everything NOT in a closed-flagged status (closed, merged, or
        // any admin-created closed status). Computed from the configured is_closed_status
        // flag rather than a hardcoded list, so custom statuses don't skew the open rate.
        $closedStatusNames = \App\Models\Helpdesk\TicketStatus::forTenant($tenantId)
            ->where('is_closed_status', true)->pluck('name');
        $unresolved = $total - $base()->whereIn('status', $closedStatusNames)->count();

        // Open rate = share of tickets not yet closed.
        $openRate = $total > 0 ? round(($unresolved / $total) * 100, 1) : 0.0;

        // Average closing time (hours) across closed tickets.
        $closedTickets = $base()->where('status', 'closed')->get(['created_at', 'updated_at']);
        $avgClosingHours = $closedTickets->count() > 0
            ? round($closedTickets->avg(fn (Ticket $t) => $t->created_at->diffInMinutes($t->updated_at)) / 60, 1)
            : 0.0;

        // Per-assignee workload: total tickets, how many closed, and the average
        // close time (hours) for that assignee's closed tickets.
        $assigneeRows = $base()->with('assignee:id,name')->get()
            ->groupBy('assigned_to')
            ->map(function ($group) {
                $closed = $group->where('status', 'closed');
                $avgHours = $closed->count() > 0
                    ? round($closed->avg(fn (Ticket $t) => $t->created_at->diffInMinutes($t->updated_at)) / 60, 1)
                    : 0.0;

                return [
                    'assignee_id'     => $group->first()->assigned_to,
                    'name'            => $group->first()->assignee->name ?? 'Unassigned',
                    'total'           => $group->count(),
                    'resolved'        => $closed->count(),
                    'avg_close_hours' => $avgHours,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $settings = app(\App\Services\Helpdesk\HelpdeskSettingsService::class);

        return [
            'total'             => $total,
            'open'              => $open,
            'in_progress'       => $inProgress,
            'closed'            => $closed,
            'unresolved'        => $unresolved,
            'open_rate'         => $openRate,
            'avg_closing_hours' => $avgClosingHours,
            // Data-driven so EVERY status/priority present is counted — incl. the
            // 'merged' status and any admin-created value. A hardcoded list here is
            // the same bug that once dropped 'urgent' from by_priority.
            'by_status'   => $this->countBreakdown($base(), 'status', $settings->statusNames($tenantId)),
            'by_priority' => $this->countBreakdown($base(), 'priority', $settings->priorityNames($tenantId)),
            'by_assignee'          => $assigneeRows,
            // Kept for backward-compat with the existing dashboard card.
            'resolved_by_assignee' => $assigneeRows->map(fn ($r) => [
                'assignee_id' => $r['assignee_id'],
                'name'        => $r['name'],
                'resolved'    => $r['resolved'],
            ])->values(),
        ];
    }

    /**
     * Count tickets grouped by a column, ordered by the tenant's configured list
     * so zero-count configured values still appear, and ANY value present in the
     * data but not (yet) configured is appended rather than silently dropped.
     */
    private function countBreakdown($query, string $column, array $orderedNames): array
    {
        $counts = $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k');

        $rows = collect($orderedNames)
            ->map(fn ($name) => [$column => $name, 'count' => (int) ($counts[$name] ?? 0)]);

        // Append any value seen in the data that isn't in the configured list.
        $extra = $counts->keys()->diff($orderedNames)
            ->map(fn ($name) => [$column => $name, 'count' => (int) $counts[$name]]);

        return $rows->concat($extra)->values()->all();
    }

    /* ── Merge (Phase 3) ────────────────────────────────────────── */

    /**
     * Merge $mergeTicketId INTO $survivorId: move its replies (and their
     * attachments), and its private notes, onto the survivor; then mark the merged
     * ticket status='merged' and point merged_into_id at the survivor so visiting
     * it can redirect. Idempotent-ish: a ticket already merged can't be re-merged.
     */
    public function mergeTicket(int $survivorId, int $mergeTicketId, int $tenantId): Ticket
    {
        if ($survivorId === $mergeTicketId) {
            throw new BusinessException('A ticket cannot be merged into itself.', 422);
        }

        $survivor = $this->findTicket($survivorId, $tenantId);
        $merged   = $this->findTicket($mergeTicketId, $tenantId);

        if ($merged->status === 'merged') {
            throw new BusinessException('That ticket has already been merged.', 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($survivor, $merged, $tenantId) {
            // Replies carry their attachments (attachments.reply_id), so moving the
            // reply rows moves the files with them.
            \App\Models\Helpdesk\TicketReply::forTenant($tenantId)
                ->where('ticket_id', $merged->id)->update(['ticket_id' => $survivor->id]);

            \App\Models\Helpdesk\TicketNote::forTenant($tenantId)
                ->where('ticket_id', $merged->id)->update(['ticket_id' => $survivor->id]);

            $merged->update(['status' => 'merged', 'merged_into_id' => $survivor->id]);
        });

        return $survivor->fresh();
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
