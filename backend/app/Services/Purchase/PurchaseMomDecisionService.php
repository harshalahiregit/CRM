<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseMomDecision;
use App\Models\User;
use App\Support\Purchase\PurchaseMomDecisionStatus as DecisionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase MOM decision register (Sangoe TPV §9): decisions recorded in a
 * meeting, each standing Active until Superseded or Rescinded. Purchase-owned:
 * touches only purchase_mom_decisions. Never reads/writes the shared/TPV
 * meeting_decisions.
 */
class PurchaseMomDecisionService
{
    /** All decisions for a meeting, ordered for display. */
    public function forMeeting(PurchaseKickoffMeeting $meeting): Collection
    {
        return $meeting->momDecisions()
            ->with(['decidedBy:id,name'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    public function create(PurchaseKickoffMeeting $meeting, array $data, User $actor): PurchaseMomDecision
    {
        $participantId = $this->resolveParticipant($meeting, $data['decided_by_participant_id'] ?? null);

        $decision = PurchaseMomDecision::create([
            'tenant_id'                   => $meeting->tenant_id,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'decision_ref'                => $this->nextDecisionRef($meeting->tenant_id),
            'decision'                    => $data['decision'],
            'decided_by_participant_id'   => $participantId,
            'decided_by_names'            => $data['decided_by_names'] ?? null,
            'impact'                      => $data['impact'] ?? null,
            'effective_date'              => $data['effective_date'] ?? null,
            'status'                      => $data['status'] ?? DecisionStatus::ACTIVE,
            'sort_order'                  => $data['sort_order'] ?? ($meeting->momDecisions()->max('sort_order') + 1),
        ]);

        $meeting->recordAudit('decision_added', $actor, "Decision {$decision->decision_ref} recorded", ['decision_id' => $decision->id]);
        Log::channel('purchase')->info('Purchase MOM decision recorded', [
            'meeting_id' => $meeting->id, 'decision_id' => $decision->id, 'actor_id' => $actor->id,
        ]);

        return $decision->fresh(['decidedBy:id,name']);
    }

    public function update(PurchaseMomDecision $decision, array $data, User $actor): PurchaseMomDecision
    {
        if (isset($data['status']) && ! DecisionStatus::isValid($data['status'])) {
            throw new BusinessException('Unknown decision status.');
        }

        $changes = array_filter([
            'decision'         => $data['decision'] ?? null,
            'decided_by_names' => $data['decided_by_names'] ?? null,
            'impact'           => $data['impact'] ?? null,
            'effective_date'   => $data['effective_date'] ?? null,
            'status'           => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('decided_by_participant_id', $data)) {
            $changes['decided_by_participant_id'] = $this->resolveParticipant($decision->meeting, $data['decided_by_participant_id']);
        }

        $decision->update($changes);
        $decision->meeting?->recordAudit('decision_updated', $actor, "Decision {$decision->decision_ref} updated", ['decision_id' => $decision->id]);

        return $decision->fresh(['decidedBy:id,name']);
    }

    public function delete(PurchaseMomDecision $decision, User $actor): void
    {
        $decision->meeting?->recordAudit('decision_deleted', $actor, "Decision {$decision->decision_ref} deleted", ['decision_id' => $decision->id]);
        $decision->delete();
    }

    /* ── internals ─────────────────────────────────────────────── */

    /** Verify a chosen participant belongs to this meeting; null clears it. */
    private function resolveParticipant(?PurchaseKickoffMeeting $meeting, $participantId): ?int
    {
        if (empty($participantId) || ! $meeting) {
            return null;
        }
        $exists = PurchaseKickoffParticipant::where('purchase_kickoff_meeting_id', $meeting->id)
            ->whereKey($participantId)->exists();
        if (! $exists) {
            throw new BusinessException('The selected decision-maker is not a participant of this meeting.');
        }

        return (int) $participantId;
    }

    /** DEC-YYYY-NNNN, sequential per tenant per year. */
    private function nextDecisionRef(int $tenantId): string
    {
        $year = now()->year;
        $count = PurchaseMomDecision::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('decision_ref', 'like', "DEC-{$year}-%")
            ->count();

        return sprintf('DEC-%d-%04d', $year, $count + 1);
    }
}
