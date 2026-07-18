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
        private \App\Services\NotificationService $notifications,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    /* ── Tickets: read ──────────────────────────────────────────── */

    /* ── Access control ─────────────────────────────────────────────
     * A helpdesk is a shared queue, but not a free-for-all. Admins and ticket
     * managers (tenant-wide managers + a department's managers) see everything.
     * A plain agent sees tickets assigned to them, unassigned tickets (so the
     * open queue stays claimable), and tickets in a department they manage.
     * Deleting a ticket is manager-only (admin or a manager of its department).
     */

    /** Portal roles. They live outside the staff queue and never see it. */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    private function jsonIds($val): \Illuminate\Support\Collection
    {
        if (is_array($val)) {
            return collect($val)->map(fn ($i) => (int) $i);
        }
        if (is_string($val) && $val !== '') {
            return collect(json_decode($val, true) ?: [])->map(fn ($i) => (int) $i);
        }

        return collect();
    }

    /** Portal roles have no seat in the staff queue — 403 before anything else. */
    private function assertInternal(?string $role): void
    {
        if (in_array($role, self::EXTERNAL_ROLES, true)) {
            throw new BusinessException('You do not have access to the helpdesk queue.', 403);
        }
    }

    /** Tenant-wide ticket managers see (and can delete) every ticket. */
    private function isGlobalTicketManager(int $tenantId, int $userId, string $role): bool
    {
        if (in_array($role, self::EXTERNAL_ROLES, true)) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }

        return $this->jsonIds(
            \App\Models\Helpdesk\HelpdeskSetting::where('tenant_id', $tenantId)->value('ticket_manager_ids')
        )->contains($userId);
    }

    /** Department ids this user manages (they're in the department's manager_ids). */
    private function managedDepartmentIds(int $tenantId, int $userId): array
    {
        return \App\Models\Helpdesk\TicketDepartment::where('tenant_id', $tenantId)
            ->get(['id', 'manager_ids'])
            ->filter(fn ($d) => $this->jsonIds($d->manager_ids)->contains($userId))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Manager of THIS ticket → admin, a global manager, or a manager of its department. */
    public function isTicketManager(Ticket $ticket, int $userId, string $role): bool
    {
        if ($this->isGlobalTicketManager($ticket->tenant_id, $userId, $role)) {
            return true;
        }

        return $ticket->department_id
            && in_array((int) $ticket->department_id, $this->managedDepartmentIds($ticket->tenant_id, $userId), true);
    }

    /** Did this user raise the ticket — created it, or their email is the requester? */
    private function isRaiser(Ticket $ticket, int $userId, ?string $userEmail): bool
    {
        if ((int) $ticket->created_by === $userId && $userId > 0) {
            return true;
        }

        return $userEmail
            && $ticket->requester_email
            && strcasecmp(trim($ticket->requester_email), trim($userEmail)) === 0;
    }

    public function canSeeTicket(Ticket $ticket, int $userId, string $role, ?string $userEmail = null): bool
    {
        // Portal users are not part of the internal queue at all. This has to be
        // the FIRST check: the unassigned-ticket rule below is deliberately
        // permissive for staff, and without this it handed every client and
        // vendor in the tenant a free window onto the open queue.
        if (in_array($role, self::EXTERNAL_ROLES, true)) {
            return false;
        }

        if ($this->isGlobalTicketManager($ticket->tenant_id, $userId, $role)) {
            return true;
        }

        // The person who RAISED it always sees it — otherwise a requester gets
        // emailed and notified about a ticket that never appears in their list.
        if ($this->isRaiser($ticket, $userId, $userEmail)) {
            return true;
        }

        // Assigned to me, or still unassigned (claimable from the open queue).
        if ((int) $ticket->assigned_to === $userId || $ticket->assigned_to === null) {
            return true;
        }

        return $ticket->department_id
            && in_array((int) $ticket->department_id, $this->managedDepartmentIds($ticket->tenant_id, $userId), true);
    }

    /** View/work access — throws 403 otherwise. Returns the ticket for reuse. */
    public function assertTicketVisible(int $ticketId, int $tenantId, int $userId, string $role, ?string $userEmail = null): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if (! $this->canSeeTicket($ticket, $userId, $role, $userEmail)) {
            throw new BusinessException('You do not have access to this ticket.', 403);
        }

        return $ticket;
    }

    /** Manage access — admin or a manager of the ticket's department only. */
    public function assertTicketManage(int $ticketId, int $tenantId, int $userId, string $role, string $action = 'delete this ticket'): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if (! $this->isTicketManager($ticket, $userId, $role)) {
            throw new BusinessException("Only an admin or a department manager can {$action}.", 403);
        }

        return $ticket;
    }

    public function listTickets(int $tenantId, array $filters = [], ?int $userId = null, ?string $role = null, ?string $userEmail = null): Collection
    {
        // The repository's visibility filter ORs in `assigned_to IS NULL` so staff
        // can claim from the open queue. That rule is only safe once portal roles
        // are out of the room — otherwise it hands them the whole unassigned queue.
        $this->assertInternal($role);

        $scoped = $userId !== null && $role !== null && ! $this->isGlobalTicketManager($tenantId, $userId, $role);
        $managedDepts = $scoped ? $this->managedDepartmentIds($tenantId, $userId) : [];
        // Scoped users also see tickets they RAISED (created, or their email is the
        // requester) — the list must match canSeeTicket exactly.
        $visibility = $scoped ? ['user_id' => $userId, 'dept_ids' => $managedDepts, 'email' => $userEmail] : null;

        // Precompute delete rights once so each row can carry a can_delete flag
        // (the frontend hides the delete button on tickets you can't remove).
        $canDeleteAll = $userId === null || $role === null || ! $scoped;   // admin / global manager

        return $this->tickets->filtered($tenantId, $filters, $visibility)
            ->map(function (Ticket $t) use ($tenantId, $canDeleteAll, $managedDepts, $userId) {
                $t = $this->decorateWithCustomer($t, $tenantId);
                $t->setAttribute('can_delete', $canDeleteAll
                    || ($t->department_id && in_array((int) $t->department_id, $managedDepts, true)));

                // REQ-05: a ticket is "new" for the requesting user until they've
                // opened it — i.e. their id is not yet in seen_by. Per-user, so the
                // flag is computed against this caller only.
                $t->setAttribute('is_new', $userId !== null
                    && ! $this->jsonIds($t->seen_by)->contains((int) $userId));

                return $t;
            });
    }

    /**
     * REQ-05: record that $userId has now seen this ticket. Idempotent — a user
     * already in seen_by is left untouched (no write, no dupes). The write goes
     * through the query builder, NOT the model, so marking a ticket seen does not
     * bump updated_at and quietly reorder the grid.
     */
    public function markSeen(int $ticketId, int $tenantId, int $userId): void
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        $seen = $this->jsonIds($ticket->seen_by);
        if ($seen->contains($userId)) {
            return;
        }

        $next = $seen->push($userId)->unique()->values()->all();

        DB::table('tickets')
            ->where('id', $ticket->id)
            ->where('tenant_id', $tenantId)
            ->update(['seen_by' => json_encode($next)]);
    }

    /**
     * REQ-04-lite: how many tickets THIS user can see but hasn't opened yet. It
     * reuses the exact visibility scoping of listTickets (same repository filter,
     * same manager/department rules) so the badge never counts a ticket the user
     * couldn't otherwise reach.
     */
    public function countUnseen(int $tenantId, int $userId, string $role, ?string $userEmail = null): int
    {
        $this->assertInternal($role);

        $scoped = ! $this->isGlobalTicketManager($tenantId, $userId, $role);
        $managedDepts = $scoped ? $this->managedDepartmentIds($tenantId, $userId) : [];
        $visibility = $scoped ? ['user_id' => $userId, 'dept_ids' => $managedDepts, 'email' => $userEmail] : null;

        return $this->tickets->filtered($tenantId, [], $visibility)
            ->filter(fn (Ticket $t) => ! $this->jsonIds($t->seen_by)->contains($userId))
            ->count();
    }

    /**
     * Ticket counts by open/in-progress/closed for the sidebar badge, scoped to
     * what THIS user can see (same visibility as listTickets). "closed" folds in
     * every status flagged is_closed_status so a renamed close status still counts.
     */
    public function statusCounts(int $tenantId, int $userId, string $role, ?string $userEmail = null): array
    {
        $this->assertInternal($role);

        $scoped = ! $this->isGlobalTicketManager($tenantId, $userId, $role);
        $managedDepts = $scoped ? $this->managedDepartmentIds($tenantId, $userId) : [];
        $visibility = $scoped ? ['user_id' => $userId, 'dept_ids' => $managedDepts, 'email' => $userEmail] : null;

        $closedNames = \App\Models\Helpdesk\TicketStatus::forTenant($tenantId)
            ->where('is_closed_status', true)->pluck('name')->all();

        $rows = $this->tickets->filtered($tenantId, [], $visibility);

        $closed = $rows->filter(fn (Ticket $t) => in_array($t->status, $closedNames, true))->count();

        return [
            'open'        => $rows->where('status', 'open')->count(),
            'in_progress' => $rows->where('status', 'in-progress')->count(),
            'closed'      => $closed,
            'total'       => $rows->count(),
        ];
    }

    public function showTicket(int $ticketId, int $tenantId, ?int $userId = null, ?string $role = null): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);
        $ticket->load(['assignee:id,name,email', 'replies.attachments', 'feedback']);

        if ($userId !== null && $role !== null) {
            $ticket->setAttribute('can_delete', $this->isTicketManager($ticket, $userId, $role));
        }

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
            // Who raised it. auth()->id() for staff/portal-created tickets; null for
            // widget/inbound-email (no session) — those match a person by email.
            'created_by'      => $data['created_by'] ?? auth()->id(),
            'customer_id'     => $data['customer_id'] ?? null,
            // The chosen department was being dropped here entirely, so every
            // ticket landed with no department — breaking department routing and
            // the Dept column. Fall back to the configured default when unset.
            'department_id'   => $data['department_id'] ?? $this->defaultDepartmentId($tenantId),
            'due_date'        => $data['due_date'] ?? null,
            'source'          => $data['source'] ?? 'internal',
            'requester_name'  => $data['requester_name'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
        ]);

        // Acknowledge the requester with their ticket number (email threading on).
        $this->mail->sendAcknowledgement($ticket);

        // ...and put it in front of the people who allocate work. A raised ticket
        // that notifies nobody just sits there until someone happens to look at
        // the list — the whole point of the ticket-manager step is that the team
        // is told, with the number, the moment it arrives.
        $this->notifyTicketManagers($ticket);

        // Raised with an assignee already set? That person owns it now.
        if ($ticket->assigned_to) {
            $this->notifications->notify(
                userId: $ticket->assigned_to,
                tenantId: $tenantId,
                type: 'ticket.assigned',
                title: "Ticket #{$ticket->id} assigned to you",
                message: $ticket->subject,
                link: "/app/helpdesk/tickets/{$ticket->id}",
                actorId: auth()->id(),
            );
        }

        return $this->decorateWithCustomer($ticket->fresh('assignee'), $tenantId);
    }

    /**
     * Notify everyone who triages incoming tickets. Recipients are resolved in
     * one pass, configured from Support Settings:
     *
     *   1. the tenant's ticket managers  — every new ticket, any department;
     *   2. the department's managers     — tickets raised against that dept;
     *   3. admins, only if neither is configured, so a fresh tenant still routes.
     *
     * Skips the raiser (notify() drops self-notification).
     */
    private function notifyTicketManagers(Ticket $ticket): void
    {
        foreach ($this->ticketManagerIds($ticket) as $uid) {
            $this->notifications->notify(
                userId: (int) $uid,
                tenantId: $ticket->tenant_id,
                type: 'ticket.created',
                title: "New ticket #{$ticket->id} — needs assignment",
                message: $ticket->subject,
                link: "/app/helpdesk/tickets/{$ticket->id}",
                actorId: auth()->id(),
            );
        }
    }

    /** The tenant's configured fallback department, used when none is chosen. */
    private function defaultDepartmentId(int $tenantId): ?int
    {
        $id = \App\Models\Helpdesk\HelpdeskSetting::where('tenant_id', $tenantId)->value('default_department_id');

        return $id ? (int) $id : null;
    }

    /** @return array<int> de-duplicated recipient ids for a newly raised ticket */
    public function ticketManagerIds(Ticket $ticket): array
    {
        $tenantId = $ticket->tenant_id;

        $global = collect(
            \App\Models\Helpdesk\HelpdeskSetting::where('tenant_id', $tenantId)->value('ticket_manager_ids') ?? []
        );

        $perDept = collect();
        if ($ticket->department_id) {
            $perDept = collect(
                \App\Models\Helpdesk\TicketDepartment::where('tenant_id', $tenantId)
                    ->whereKey($ticket->department_id)->value('manager_ids') ?? []
            );
        }

        // Admins are global ticket managers BY DEFINITION (isGlobalTicketManager
        // returns true for any admin), so they are ALWAYS in the loop — not merely
        // a fallback for when no department manager is configured. Previously the
        // admin fallback only fired if the list was otherwise empty, so the moment
        // a department got its own manager the admin stopped seeing that
        // department's activity entirely. An admin should track everything.
        $admins = \App\Models\User::where('tenant_id', $tenantId)
            ->where('status', 'active')->where('role', 'admin')->pluck('id');

        $ids = $global->merge($perDept)->merge($admins)->map(fn ($i) => (int) $i)->unique();

        // Only ever notify real, active, internal people.
        return \App\Models\User::where('tenant_id', $tenantId)
            ->whereIn('id', $ids->all())
            ->where('status', 'active')
            ->whereNotIn('role', ['client', 'vendor', 'third_party_vendor'])
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * The raiser as a CRM user, if there is one: the creator, else a user whose
     * email is the requester. Returns null for widget/anonymous requesters who
     * have no account (they're reached by email, not in-app).
     */
    private function raiserUserId(Ticket $ticket): ?int
    {
        if ($ticket->created_by) {
            $creator = \App\Models\User::where('id', $ticket->created_by)
                ->where('tenant_id', $ticket->tenant_id)
                ->whereNotIn('role', self::EXTERNAL_ROLES)->value('id');
            if ($creator) {
                return (int) $creator;
            }
        }

        if ($ticket->requester_email) {
            $byEmail = \App\Models\User::where('tenant_id', $ticket->tenant_id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($ticket->requester_email))])
                ->whereNotIn('role', self::EXTERNAL_ROLES)->value('id');
            if ($byEmail) {
                return (int) $byEmail;
            }
        }

        return null;
    }

    /** In-app ping to the raiser (if a CRM user). Actor is self-suppressed inside notify(). */
    private function notifyRaiser(Ticket $ticket, string $type, string $title, ?string $message): void
    {
        $uid = $this->raiserUserId($ticket);
        if ($uid) {
            $this->notifications->notify(
                userId: $uid,
                tenantId: $ticket->tenant_id,
                type: $type,
                title: $title,
                message: $message,
                link: "/app/helpdesk/tickets/{$ticket->id}",
                actorId: auth()->id(),
            );
        }
    }

    public function updateTicket(int $ticketId, array $data, int $tenantId): Ticket
    {
        $ticket = $this->findTicket($ticketId, $tenantId);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id'])
            && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $oldStatus   = $ticket->status;
        $oldAssignee = $ticket->assigned_to === null ? null : (int) $ticket->assigned_to;

        // department_id was missing from this whitelist, so a department change
        // sent through PUT validated cleanly and was then silently thrown away —
        // the caller got a 200 and the old department back.
        $ticket->fill(array_intersect_key($data, array_flip([
            'subject', 'description', 'status', 'priority',
            'assigned_to', 'customer_id', 'department_id', 'due_date',
        ])));
        $ticket->save();

        if (array_key_exists('status', $data) && $data['status'] !== $oldStatus) {
            $this->sla->onStatusChange($ticket, $oldStatus, $ticket->status);

            // This edit path used to skip the closure event that changeStatus()
            // fires, so closing a ticket via PUT resolved it in the UI but never
            // asked the customer for feedback. Same transition, same event.
            if ($ticket->status === 'closed' && $oldStatus !== 'closed') {
                TicketClosed::dispatch($ticket->fresh());
            }
        }

        // Likewise, being handed a ticket through the edit form told the new
        // owner nothing — the notification only existed on create and on the
        // dedicated assign route.
        $newAssignee = $ticket->assigned_to === null ? null : (int) $ticket->assigned_to;
        if ($newAssignee !== null && $newAssignee !== $oldAssignee) {
            $this->notifications->notify(
                userId: $newAssignee,
                tenantId: $tenantId,
                type: 'ticket.assigned',
                title: "Ticket #{$ticket->id} assigned to you",
                message: $ticket->subject,
                link: "/app/helpdesk/tickets/{$ticket->id}",
                actorId: auth()->id(),
            );
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

        // Keep the raiser in the loop in-app, mirroring the status email they get.
        if ($status !== $was) {
            $this->notifyRaiser($ticket, 'ticket.status', "Ticket #{$ticket->id} is now {$status}", $ticket->subject);
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
        // Captured before the write — the transaction may auto-reopen the ticket.
        $wasClosed = $ticket->status === 'closed';

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

        $fresh = $ticket->fresh();

        if ($data['sender_type'] !== 'client') {
            // A staff reply is delivered to the customer as email (after the write
            // commits). Client-origin replies (portal/inbound) are not echoed back.
            $agentName = ! empty($data['sender_id'])
                ? (\App\Models\User::find($data['sender_id'])?->name ?? 'Support')
                : 'Support';
            $this->mail->sendStaffReply($fresh, $reply, $agentName);

            // The raiser gets the reply by email; if they're also a CRM user, give
            // them the in-app bell too so the two surfaces agree.
            $this->notifyRaiser($fresh, 'ticket.reply', "New reply on your ticket #{$fresh->id}", $data['message'] ?? $fresh->subject);
        } else {
            // A customer wrote in — tell whoever owns the ticket. If the reply
            // reopened a closed ticket, say so: that's the part an agent must not
            // miss (it moved back onto their queue).
            $reopened = $wasClosed && $fresh->status !== 'closed';
            $this->notifications->notify(
                userId: $fresh->assigned_to,
                tenantId: $tenantId,
                type: $reopened ? 'ticket.reopened' : 'ticket.customer_replied',
                title: $reopened
                    ? "Ticket #{$fresh->id} reopened by the customer"
                    : "New customer reply on ticket #{$fresh->id}",
                message: $data['message'] ?? $fresh->subject,
                link: "/app/helpdesk/tickets/{$fresh->id}",
            );
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
    /**
     * Hours between a ticket's creation and its last update, as a SQL expression.
     *
     * Date arithmetic is the one thing that is never portable, so it is resolved
     * from the live driver rather than hardcoded to SQLite. Semantics are kept
     * exactly as before (created_at → updated_at); resolved_at would be the truer
     * source but it is only populated for tickets closed since the SLA engine
     * landed, so switching to it now would silently mix two definitions.
     */
    private function elapsedHoursExpr(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => '(julianday(updated_at) - julianday(created_at)) * 24.0',
            'mysql', 'mariadb' => 'TIMESTAMPDIFF(SECOND, created_at, updated_at) / 3600.0',
            'pgsql'  => 'EXTRACT(EPOCH FROM (updated_at - created_at)) / 3600.0',
            default  => '(julianday(updated_at) - julianday(created_at)) * 24.0',
        };
    }

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
        //
        // This whole section used to pull every ticket in the tenant into PHP with
        // ->get() and group in memory — twice. At a few hundred tickets nobody
        // notices; at 10k it is 10k hydrated models per dashboard load. The
        // arithmetic is a database's job, so it now happens there and only the
        // aggregated rows come back.
        $avgClosingHours = round((float) $base()->where('status', 'closed')
            ->avg(DB::raw($this->elapsedHoursExpr())) ?: 0.0, 1);

        // Per-assignee workload: total tickets, how many closed, and the average
        // close time (hours) for that assignee's closed tickets — one grouped
        // query instead of a full table scan through PHP.
        $elapsed = $this->elapsedHoursExpr();
        $rows = $base()
            ->selectRaw('assigned_to')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as resolved")
            ->selectRaw("AVG(CASE WHEN status = 'closed' THEN ({$elapsed}) END) as avg_close_hours")
            ->groupBy('assigned_to')
            ->orderByDesc('total')
            ->get();

        // One extra query resolves every assignee name; the previous code got
        // these via an eager-load on the full ticket set.
        $names = \App\Models\User::whereIn('id', $rows->pluck('assigned_to')->filter()->all())
            ->pluck('name', 'id');

        $assigneeRows = $rows->map(fn ($r) => [
            'assignee_id'     => $r->assigned_to,
            'name'            => $r->assigned_to ? ($names[$r->assigned_to] ?? 'Unknown') : 'Unassigned',
            'total'           => (int) $r->total,
            'resolved'        => (int) $r->resolved,
            'avg_close_hours' => round((float) $r->avg_close_hours, 1),
        ])->values();

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
            // Per-department load so the admin can track which queue is busy.
            // department_id stores an id (not a name), so this resolves names from
            // the tenant's configured departments rather than reusing countBreakdown.
            'by_department'        => $this->departmentBreakdown($tenantId),
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
     * Ticket count per department, resolving department_id → name from the
     * tenant's configured departments so every department shows (even at zero),
     * with a "No department" bucket for tickets that were never routed.
     */
    private function departmentBreakdown(int $tenantId): array
    {
        $names = \App\Models\Helpdesk\TicketDepartment::forTenant($tenantId)
            ->orderBy('order')->pluck('name', 'id');

        $counts = Ticket::forTenant($tenantId)
            ->selectRaw('department_id, count(*) as c')
            ->groupBy('department_id')->pluck('c', 'department_id');

        $rows = $names->map(fn ($name, $id) => [
            'department_id' => $id,
            'department'    => $name,
            'count'         => (int) ($counts[$id] ?? 0),
        ])->values();

        $noDept = Ticket::forTenant($tenantId)->whereNull('department_id')->count();
        if ($noDept > 0) {
            $rows->push(['department_id' => null, 'department' => 'No department', 'count' => $noDept]);
        }

        return $rows->all();
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

    /**
     * Reopen a resolved ticket from the one-click link in the closure email.
     *
     * Public / no login — the signed URL is the authorization, same as the CSAT
     * one-click. Idempotent: clicking it on an already-open ticket is a no-op.
     * Reopening puts the work back on someone's queue, so the assigned agent (or,
     * if unassigned, the ticket's managers) is told.
     */
    public function reopenOneClick(int $ticketId): Ticket
    {
        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        $closedNames = \App\Models\Helpdesk\TicketStatus::forTenant($ticket->tenant_id)
            ->where('is_closed_status', true)->pluck('name')->all();

        // Already open → nothing to do (a double-click, or a stale link).
        if (! in_array($ticket->status, $closedNames, true)) {
            return $ticket;
        }

        $openStatus = \App\Models\Helpdesk\TicketStatus::forTenant($ticket->tenant_id)
            ->where('is_closed_status', false)->orderBy('order')->value('name') ?? 'open';

        $was = $ticket->status;
        $ticket->update(['status' => $openStatus]);
        $this->sla->onStatusChange($ticket, $was, $openStatus);

        if ($ticket->assigned_to) {
            $this->notifications->notify(
                userId: $ticket->assigned_to,
                tenantId: $ticket->tenant_id,
                type: 'ticket.reopened',
                title: "Ticket #{$ticket->id} reopened by the customer",
                message: $ticket->subject,
                link: "/app/helpdesk/tickets/{$ticket->id}",
            );
        } else {
            // Nobody owns it → put it back in front of the managers.
            foreach ($this->ticketManagerIds($ticket) as $mgrId) {
                $this->notifications->notify(
                    userId: $mgrId,
                    tenantId: $ticket->tenant_id,
                    type: 'ticket.reopened',
                    title: "Ticket #{$ticket->id} reopened by the customer",
                    message: $ticket->subject,
                    link: "/app/helpdesk/tickets/{$ticket->id}",
                );
            }
        }

        return $ticket;
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
