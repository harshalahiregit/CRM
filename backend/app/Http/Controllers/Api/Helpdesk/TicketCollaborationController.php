<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\TicketCollaborationService;
use Illuminate\Http\Request;

/**
 * Phase 2 — private notes, reminders and related-ticket links on a ticket.
 */
class TicketCollaborationController extends Controller
{
    use ApiResponse;

    public function __construct(private TicketCollaborationService $collab)
    {
    }

    /* ── Notes ─────────────────────────────────────────────────── */
    public function notes(Request $request, int $ticket)
    {
        return $this->success($this->collab->listNotes($ticket, $request->user()->tenant_id), 'Notes retrieved');
    }

    public function storeNote(Request $request, int $ticket)
    {
        $data = $request->validate(['content' => 'required|string']);
        $note = $this->collab->addNote($ticket, $data['content'], $request->user()->tenant_id, $request->user()->id);

        return $this->success($note, 'Note added', 201);
    }

    /* ── Reminders ─────────────────────────────────────────────── */
    public function reminders(Request $request, int $ticket)
    {
        return $this->success($this->collab->listReminders($ticket, $request->user()->tenant_id), 'Reminders retrieved');
    }

    public function storeReminder(Request $request, int $ticket)
    {
        $data = $request->validate([
            'remind_at' => 'required|date',
            'note'      => 'nullable|string|max:255',
        ]);
        $reminder = $this->collab->addReminder($ticket, $data, $request->user()->tenant_id, $request->user()->id);

        return $this->success($reminder, 'Reminder set', 201);
    }

    public function reminderDone(Request $request, int $reminder)
    {
        return $this->success($this->collab->markReminderDone($reminder, $request->user()->tenant_id), 'Reminder updated');
    }

    /* ── Related tickets ───────────────────────────────────────── */
    public function related(Request $request, int $ticket)
    {
        return $this->success($this->collab->listRelated($ticket, $request->user()->tenant_id), 'Related tickets retrieved');
    }

    public function storeRelated(Request $request, int $ticket)
    {
        $data = $request->validate(['related_ticket_id' => 'required|integer']);
        $this->collab->addRelated($ticket, $data['related_ticket_id'], $request->user()->tenant_id);

        return $this->success($this->collab->listRelated($ticket, $request->user()->tenant_id), 'Ticket linked');
    }

    public function destroyRelated(Request $request, int $ticket, int $relatedId)
    {
        $this->collab->removeRelated($ticket, $relatedId, $request->user()->tenant_id);

        return $this->success(null, 'Link removed');
    }
}
