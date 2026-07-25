<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\User;
use App\Repositories\Helpdesk\TicketRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Owns ticket → user assignment and the assignee's task list.
 * An assigned ticket surfaces on that user's dashboard via myTasks().
 */
class TicketAssignmentService
{
    public function __construct(
        private TicketRepository $tickets,
        private HelpdeskMailService $mail,
        private \App\Services\NotificationService $notifications,
    ) {
    }

    /**
     * Assign (or unassign, when $userId is null) a ticket to a user.
     * The user must belong to the same tenant.
     */
    public function assign(int $ticketId, ?int $userId, int $tenantId): Ticket
    {
        $ticket = $this->tickets->findForTenant($ticketId, $tenantId);

        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        if ($userId !== null) {
            $belongs = User::where('id', $userId)->where('tenant_id', $tenantId)->exists();
            if (! $belongs) {
                throw new BusinessException('Cannot assign to a user outside this workspace.', 422);
            }
        }

        $previous = $ticket->assigned_to;
        $ticket->update(['assigned_to' => $userId]);

        // Reassignment is activity: re-flag the ticket "new" for everyone but the
        // person who moved it, so it resurfaces with the grid's dot (the update
        // above already bumped updated_at, floating it to the top of the list).
        if ($userId !== $previous) {
            $actor = auth()->id();
            \Illuminate\Support\Facades\DB::table('tickets')
                ->where('id', $ticket->id)->where('tenant_id', $tenantId)
                ->update(['seen_by' => json_encode($actor ? [$actor] : [])]);
        }

        Log::info('Helpdesk ticket assignment', [
            'ticket' => $ticket->id, 'assigned_to' => $userId, 'tenant' => $tenantId,
        ]);

        // Notify the newly-assigned agent (skip re-assign to the same user).
        if ($userId !== null && $userId !== $previous) {
            $fresh = $ticket->fresh('assignee');
            $this->mail->sendAssignment($fresh, $userId);
            $this->notifications->notify(
                userId: $userId,
                tenantId: $tenantId,
                type: 'ticket.assigned',
                title: "Ticket #{$fresh->id} assigned to you",
                message: $fresh->subject,
                link: "/app/helpdesk/tickets/{$fresh->id}",
                actorId: auth()->id(),
            );

            // Managers (incl. admins) also see assignment activity — so the admin's
            // feed reflects who work is going to, not just new tickets. The actor is
            // self-suppressed; the assignee was already told above. Resolved lazily
            // to avoid a constructor dependency cycle with HelpdeskService.
            $agentName = $fresh->assignee->name ?? 'an agent';
            foreach (app(\App\Services\Helpdesk\HelpdeskService::class)->ticketManagerIds($fresh) as $mgrId) {
                if ((int) $mgrId === (int) $userId) {
                    continue; // already notified as the assignee
                }
                $this->notifications->notify(
                    userId: (int) $mgrId,
                    tenantId: $tenantId,
                    type: 'ticket.assigned',
                    title: "Ticket #{$fresh->id} assigned to {$agentName}",
                    message: $fresh->subject,
                    link: "/app/helpdesk/tickets/{$fresh->id}",
                    actorId: auth()->id(),
                );
            }
        }

        return $ticket->fresh('assignee');
    }

    /* ── Auto-assignment (REQ-06) ───────────────────────────────────
     *
     * Tickets raised by clients and by the public widget arrive with no owner —
     * there is no human on that side of the form to pick one — so they sat in the
     * queue until somebody happened to look. autoAssign() picks an owner using the
     * tenant's configured strategy and then routes through assign(), so the
     * assignment email and the ticket.assigned notification fire exactly as they
     * do for a manual assignment.
     *
     * Strategy lives in helpdesk_settings.auto_assign_strategy and defaults to
     * 'none' — existing tenants keep manual assignment until they opt in.
     */

    /** Portal roles. They are outside the staff queue and are never assignees. */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    /**
     * Pick and set an owner for a freshly-created ticket. Never overwrites an
     * assignee that is already set. Returns the ticket (assigned or untouched).
     */
    public function autoAssign(Ticket $ticket): Ticket
    {
        if ($ticket->assigned_to) {
            return $ticket;
        }

        $settings = \App\Models\Helpdesk\HelpdeskSetting::where('tenant_id', $ticket->tenant_id)->first();
        $strategy = $settings->auto_assign_strategy ?? 'none';

        if ($strategy === 'none') {
            return $ticket;
        }

        $userId = match ($strategy) {
            'department_manager' => $this->pickDepartmentManager($ticket),
            'least_busy'         => $this->pickLeastBusy($ticket->tenant_id),
            'round_robin'        => $this->pickRoundRobin($ticket->tenant_id, $settings?->last_auto_assigned_user_id),
            default              => null,
        };

        // A strategy can legitimately resolve nobody (a department with no
        // managers, a tenant with no active internal agents). Fall back to the
        // configured default assignee — but only if they still qualify, so a
        // stale id pointing at a deactivated or externalised user can't be used.
        if (! $userId && $settings?->default_assignee_id) {
            $userId = $this->candidates($ticket->tenant_id)
                ->where('id', $settings->default_assignee_id)->value('id');
        }

        if (! $userId) {
            Log::info("Auto-assign ({$strategy}) found no eligible assignee for ticket #{$ticket->id}");

            return $ticket;
        }

        // Advance the round-robin cursor before assigning, so a failure in the
        // assignment can't park the rotation on one person forever.
        if ($settings && $strategy === 'round_robin') {
            $settings->update(['last_auto_assigned_user_id' => $userId]);
        }

        Log::info("Auto-assigned ticket #{$ticket->id} to user {$userId} via {$strategy}");

        return $this->assign($ticket->id, (int) $userId, $ticket->tenant_id);
    }

    /**
     * Every user eligible to receive a ticket: this tenant, active, internal.
     *
     * This is the single gate for ALL strategies — external roles (client, vendor,
     * third_party_vendor) and inactive users are excluded here and nowhere else,
     * so no strategy can reintroduce the bug where a portal user was handed a
     * ticket they can't even open.
     */
    private function candidates(int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotIn('role', self::EXTERNAL_ROLES);
    }

    /** Least-loaded manager of the ticket's own department. */
    private function pickDepartmentManager(Ticket $ticket): ?int
    {
        if (! $ticket->department_id) {
            return null;
        }

        $managerIds = collect(
            \App\Models\Helpdesk\TicketDepartment::where('tenant_id', $ticket->tenant_id)
                ->whereKey($ticket->department_id)->value('manager_ids') ?? []
        )->map(fn ($i) => (int) $i)->all();

        if (! $managerIds) {
            return null;
        }

        return $this->leastLoadedOf($ticket->tenant_id, $this->candidates($ticket->tenant_id)
            ->whereIn('id', $managerIds)->pluck('id')->all());
    }

    /** Active internal agent carrying the fewest open tickets. */
    private function pickLeastBusy(int $tenantId): ?int
    {
        return $this->leastLoadedOf($tenantId, $this->candidates($tenantId)->pluck('id')->all());
    }

    /**
     * Next active internal agent after the previous auto-assignee, by id, wrapping
     * at the end. A stable order over a changing roster: adding or removing an
     * agent shifts the rotation but never stalls it, and an unknown/removed cursor
     * simply restarts from the first agent.
     */
    private function pickRoundRobin(int $tenantId, ?int $lastUserId): ?int
    {
        $ids = $this->candidates($tenantId)->orderBy('id')->pluck('id')
            ->map(fn ($i) => (int) $i)->all();

        if (! $ids) {
            return null;
        }

        $position = $lastUserId ? array_search((int) $lastUserId, $ids, true) : false;

        return $position === false ? $ids[0] : $ids[($position + 1) % count($ids)];
    }

    /**
     * Of the given users, whoever has the fewest tickets in a non-closed status.
     * "Open" is derived from the tenant's is_closed_status flags rather than a
     * hardcoded list, so custom statuses count correctly. Ties break on the lowest
     * user id, keeping the choice deterministic.
     *
     * @param  array<int>  $userIds
     */
    private function leastLoadedOf(int $tenantId, array $userIds): ?int
    {
        if (! $userIds) {
            return null;
        }

        $closedStatuses = \App\Models\Helpdesk\TicketStatus::where('tenant_id', $tenantId)
            ->where('is_closed_status', true)->pluck('name')->all();

        $openCounts = Ticket::forTenant($tenantId)
            ->whereIn('assigned_to', $userIds)
            ->when($closedStatuses, fn ($q) => $q->whereNotIn('status', $closedStatuses))
            ->selectRaw('assigned_to, count(*) as c')
            ->groupBy('assigned_to')
            ->pluck('c', 'assigned_to');

        sort($userIds);

        return collect($userIds)
            ->sortBy(fn ($id) => (int) ($openCounts[$id] ?? 0))
            ->first();
    }

    /**
     * Integration 3e — the unified assignee dashboard. Pulls BOTH the tickets
     * assigned to the user (tickets.assigned_to) AND the tasks assigned to them
     * (task_assignees), normalized and tagged by `source` (ticket | task).
     * The Task side is guarded so this still works before the Task module ships.
     */
    public function myTasks(int $userId, int $tenantId): array
    {
        $items = $this->tickets->assignedTo($userId, $tenantId)->map(fn ($t) => [
            'source'    => 'ticket',
            'id'        => $t->id,
            'title'     => $t->subject,
            'priority'  => $t->priority,
            'status'    => $t->status,
            'due_date'  => $t->due_date,
            'link'      => "/app/helpdesk/tickets/{$t->id}",
        ])->all();

        if (\Illuminate\Support\Facades\Schema::hasTable('tasks')) {
            $tasks = \App\Models\Task\Task::forTenant($tenantId)
                ->whereIn('status', ['not_started', 'in_progress', 'awaiting_feedback', 'testing'])
                ->whereHas('assignees', fn ($q) => $q->where('user_id', $userId))
                ->get();

            foreach ($tasks as $t) {
                $items[] = [
                    'source'   => 'task',
                    'id'       => $t->id,
                    'title'    => $t->name,
                    'priority' => $t->priority,
                    'status'   => $t->status,
                    'due_date' => $t->due_date,
                    'link'     => "/app/tasks/{$t->id}",
                ];
            }
        }

        // Urgent-first, then by soonest due date.
        $rank = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($items, fn ($a, $b) => ($rank[$a['priority']] ?? 9) <=> ($rank[$b['priority']] ?? 9)
            ?: strcmp((string) $a['due_date'], (string) $b['due_date']));

        return $items;
    }
}
