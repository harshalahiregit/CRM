<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseMomAgendaItem;
use App\Models\User;
use App\Support\Purchase\PurchaseMeetingTypeCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase agenda builder (Meeting.docx §3/§4/§7). Structured agenda items
 * that can be created manually, loaded from the meeting-type template, or copied
 * from the vendor's previous meeting. Purchase-owned: touches only
 * purchase_mom_agenda_items.
 */
class PurchaseMomAgendaService
{
    /** All agenda items for a meeting, ordered for display. */
    public function forMeeting(PurchaseKickoffMeeting $meeting): Collection
    {
        return $meeting->agendaItems()
            ->with(['owner:id,name'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    public function create(PurchaseKickoffMeeting $meeting, array $data, User $actor): PurchaseMomAgendaItem
    {
        if (trim((string) ($data['item'] ?? '')) === '') {
            throw new BusinessException('An agenda item needs a title.');
        }
        $item = $this->store($meeting, $data, $meeting->agendaItems()->max('sort_order') + 1);
        $meeting->recordAudit('agenda_added', $actor, "Agenda item '{$item->item}' added", ['agenda_item_id' => $item->id]);

        return $item->fresh(['owner:id,name']);
    }

    public function update(PurchaseMomAgendaItem $item, array $data, User $actor): PurchaseMomAgendaItem
    {
        $changes = array_filter([
            'item'             => $data['item'] ?? null,
            'description'      => $data['description'] ?? null,
            'owner_names'      => $data['owner_names'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'priority'         => $data['priority'] ?? null,
            'discussion'       => $data['discussion'] ?? null,
            'decision'         => $data['decision'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('owner_participant_id', $data)) {
            $changes['owner_participant_id'] = $this->resolveParticipant($item->meeting, $data['owner_participant_id']);
        }

        $item->update($changes);
        $item->meeting?->recordAudit('agenda_updated', $actor, "Agenda item updated", ['agenda_item_id' => $item->id]);

        return $item->fresh(['owner:id,name']);
    }

    public function delete(PurchaseMomAgendaItem $item, User $actor): void
    {
        $item->meeting?->recordAudit('agenda_deleted', $actor, "Agenda item '{$item->item}' removed", ['agenda_item_id' => $item->id]);
        $item->delete();
    }

    /**
     * Load the agenda from the meeting-type template (Meeting.docx §4). Appends
     * the template rows after any existing items; returns the full list.
     */
    public function loadTemplate(PurchaseKickoffMeeting $meeting, User $actor): Collection
    {
        $templates = PurchaseMeetingTypeCatalog::templates();
        $rows = $templates[$meeting->meeting_type] ?? [];
        if (empty($rows)) {
            throw new BusinessException('No agenda template is defined for the "'.$meeting->meeting_type_label.'" meeting type.');
        }

        $order = $meeting->agendaItems()->max('sort_order');
        foreach ($rows as $r) {
            $this->store($meeting, [
                'item'             => $r['item'] ?? 'Agenda item',
                'duration_minutes' => $r['duration_minutes'] ?? null,
                'priority'         => $r['priority'] ?? null,
            ], ++$order);
        }
        $meeting->recordAudit('agenda_templated', $actor, 'Agenda loaded from the '.$meeting->meeting_type_label.' template ('.count($rows).' items)');

        return $this->forMeeting($meeting->fresh());
    }

    /**
     * Copy the agenda from the vendor's most recent earlier meeting (Meeting.docx
     * §4 "copied from previous meeting"). Item text/owner/duration/priority only —
     * not the discussion/decision (those belong to that meeting).
     */
    public function copyFromPrevious(PurchaseKickoffMeeting $meeting, User $actor): Collection
    {
        $previous = PurchaseKickoffMeeting::forTenant($meeting->tenant_id)
            ->where('purchase_vendor_id', $meeting->purchase_vendor_id)
            ->where('id', '!=', $meeting->id)
            ->whereHas('agendaItems')
            ->latest('scheduled_at')->latest('id')
            ->first();
        if (! $previous) {
            throw new BusinessException('No previous meeting with an agenda was found for this vendor.');
        }

        $order = $meeting->agendaItems()->max('sort_order');
        foreach ($previous->agendaItems()->orderBy('sort_order')->orderBy('id')->get() as $src) {
            $this->store($meeting, [
                'item'             => $src->item,
                'description'      => $src->description,
                'owner_names'      => $src->owner_names,
                'duration_minutes' => $src->duration_minutes,
                'priority'         => $src->priority,
            ], ++$order);
        }
        $meeting->recordAudit('agenda_copied', $actor, "Agenda copied from {$previous->reference}");

        return $this->forMeeting($meeting->fresh());
    }

    /* ── internals ─────────────────────────────────────────────── */

    private function store(PurchaseKickoffMeeting $meeting, array $data, int $order): PurchaseMomAgendaItem
    {
        return PurchaseMomAgendaItem::create([
            'tenant_id'                   => $meeting->tenant_id,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'item'                        => $data['item'] ?? 'Agenda item',
            'description'                 => $data['description'] ?? null,
            'owner_participant_id'        => $this->resolveParticipant($meeting, $data['owner_participant_id'] ?? null),
            'owner_names'                 => $data['owner_names'] ?? null,
            'duration_minutes'            => $data['duration_minutes'] ?? null,
            'priority'                    => $data['priority'] ?? null,
            'discussion'                  => $data['discussion'] ?? null,
            'decision'                    => $data['decision'] ?? null,
            'sort_order'                  => $data['sort_order'] ?? $order,
        ]);
    }

    private function resolveParticipant(?PurchaseKickoffMeeting $meeting, $participantId): ?int
    {
        if (empty($participantId) || ! $meeting) {
            return null;
        }
        $exists = PurchaseKickoffParticipant::where('purchase_kickoff_meeting_id', $meeting->id)
            ->whereKey($participantId)->exists();

        return $exists ? (int) $participantId : null;
    }
}
