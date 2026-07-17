<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketNote;
use App\Models\Helpdesk\TicketReminder;
use App\Models\Helpdesk\TicketRelation;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — private notes, reminders and related-ticket links for a ticket.
 * All tenant-scoped; the ticket is validated to belong to the tenant first.
 */
class TicketCollaborationService
{
    private function ticket(int $ticketId, int $tenantId): Ticket
    {
        $ticket = Ticket::forTenant($tenantId)->find($ticketId);
        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        return $ticket;
    }

    /* ── Notes (private) ────────────────────────────────────────── */

    public function listNotes(int $ticketId, int $tenantId)
    {
        $this->ticket($ticketId, $tenantId);

        return TicketNote::forTenant($tenantId)->where('ticket_id', $ticketId)
            ->with('user:id,name')->latest()->get();
    }

    public function addNote(int $ticketId, string $content, int $tenantId, int $userId): TicketNote
    {
        $this->ticket($ticketId, $tenantId);

        return TicketNote::create([
            'tenant_id' => $tenantId, 'ticket_id' => $ticketId, 'user_id' => $userId, 'content' => $content,
        ])->load('user:id,name');
    }

    /* ── Reminders ──────────────────────────────────────────────── */

    public function listReminders(int $ticketId, int $tenantId)
    {
        $this->ticket($ticketId, $tenantId);

        return TicketReminder::forTenant($tenantId)->where('ticket_id', $ticketId)
            ->with('user:id,name')->orderBy('remind_at')->get();
    }

    public function addReminder(int $ticketId, array $data, int $tenantId, int $userId): TicketReminder
    {
        $this->ticket($ticketId, $tenantId);

        return TicketReminder::create([
            'tenant_id' => $tenantId, 'ticket_id' => $ticketId, 'user_id' => $userId,
            'remind_at' => $data['remind_at'], 'note' => $data['note'] ?? null,
        ])->load('user:id,name');
    }

    /**
     * A reminder is personal — it belongs to the agent who set it. This was
     * scoped by tenant alone, so any colleague could toggle anyone's reminder by
     * guessing an id, and because the write is a negation rather than a set, a
     * second call flipped it back and left no trace.
     */
    public function markReminderDone(int $reminderId, int $tenantId, int $userId): TicketReminder
    {
        $reminder = TicketReminder::forTenant($tenantId)->where('user_id', $userId)->find($reminderId);
        if (! $reminder) {
            throw new BusinessException('Reminder not found.', 404);
        }
        $reminder->update(['is_done' => ! $reminder->is_done]);

        return $reminder->fresh('user:id,name');
    }

    /* ── Related tickets (bidirectional) ────────────────────────── */

    public function listRelated(int $ticketId, int $tenantId)
    {
        $this->ticket($ticketId, $tenantId);

        $relatedIds = TicketRelation::forTenant($tenantId)->where('ticket_id', $ticketId)
            ->pluck('related_ticket_id');

        // Join back to real tickets so deleted ones simply drop out.
        return Ticket::forTenant($tenantId)->whereIn('id', $relatedIds)
            ->get(['id', 'subject', 'status', 'priority']);
    }

    public function addRelated(int $ticketId, int $relatedId, int $tenantId): void
    {
        if ($ticketId === $relatedId) {
            throw new BusinessException('A ticket cannot be related to itself.', 422);
        }
        $this->ticket($ticketId, $tenantId);
        $this->ticket($relatedId, $tenantId); // both must exist in the tenant

        // Link both directions in one action.
        DB::transaction(function () use ($ticketId, $relatedId, $tenantId) {
            foreach ([[$ticketId, $relatedId], [$relatedId, $ticketId]] as [$a, $b]) {
                TicketRelation::firstOrCreate(
                    ['ticket_id' => $a, 'related_ticket_id' => $b],
                    ['tenant_id' => $tenantId],
                );
            }
        });
    }

    public function removeRelated(int $ticketId, int $relatedId, int $tenantId): void
    {
        $this->ticket($ticketId, $tenantId);

        TicketRelation::forTenant($tenantId)
            ->where(fn ($q) => $q->where(['ticket_id' => $ticketId, 'related_ticket_id' => $relatedId])
                ->orWhere(['ticket_id' => $relatedId, 'related_ticket_id' => $ticketId]))
            ->delete();
    }
}
