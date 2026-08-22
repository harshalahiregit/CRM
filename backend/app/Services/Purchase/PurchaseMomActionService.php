<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseKickoffParticipant;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\User;
use App\Support\Purchase\PurchaseMomActionStatus as ActionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The Purchase MOM action engine (Sangoe TPV §9): the register of action items a
 * meeting produces — each with an owner (Rule 11) and, at closure, evidence or a
 * verification note (Rule 12). Purchase-owned: touches only
 * purchase_mom_action_items and the private `purchase_kickoff_docs` disk. Never
 * reads/writes the shared/TPV kickoff_mom_items.
 */
class PurchaseMomActionService
{
    private const DISK = 'purchase_kickoff_docs';

    /** All actions for a meeting, ordered for display. */
    public function forMeeting(PurchaseKickoffMeeting $meeting): Collection
    {
        return $meeting->actionItems()
            ->with(['responsible:id,name', 'verifier:id,name'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /** Raise a new action item. An owner is required (Rule 11). */
    public function create(PurchaseKickoffMeeting $meeting, array $data, User $actor): PurchaseMomActionItem
    {
        $this->assertOwner($data);
        $participantId = $this->resolveParticipant($meeting, $data['responsible_participant_id'] ?? null);

        $action = PurchaseMomActionItem::create([
            'tenant_id'                   => $meeting->tenant_id,
            'purchase_kickoff_meeting_id' => $meeting->id,
            'action_ref'                  => $this->nextActionRef($meeting->tenant_id),
            'description'                 => $data['description'],
            'responsible_participant_id'  => $participantId,
            'responsible_names'           => $data['responsible_names'] ?? null,
            'responsible_org'             => $data['responsible_org'] ?? null,
            'status'                      => ActionStatus::OPEN,
            'priority'                    => $data['priority'] ?? null,
            'target_date'                 => $data['target_date'] ?? null,
            'remark'                      => $data['remark'] ?? null,
            'notes'                       => $data['notes'] ?? null,
            'sort_order'                  => $data['sort_order'] ?? ($meeting->actionItems()->max('sort_order') + 1),
        ]);

        $meeting->recordAudit('action_added', $actor, "Action {$action->action_ref} raised", [
            'action_id' => $action->id,
        ]);
        Log::channel('purchase')->info('Purchase MOM action raised', [
            'meeting_id' => $meeting->id, 'action_id' => $action->id, 'actor_id' => $actor->id,
        ]);

        return $action->fresh(['responsible:id,name', 'verifier:id,name']);
    }

    /** Edit an action's descriptive fields (status goes through progress()). */
    public function update(PurchaseMomActionItem $action, array $data, User $actor): PurchaseMomActionItem
    {
        if (array_key_exists('responsible_participant_id', $data) || array_key_exists('responsible_names', $data)) {
            $this->assertOwner([
                'responsible_participant_id' => $data['responsible_participant_id'] ?? $action->responsible_participant_id,
                'responsible_names'          => $data['responsible_names'] ?? $action->responsible_names,
            ]);
        }

        $changes = array_filter([
            'description'        => $data['description'] ?? null,
            'responsible_names'  => $data['responsible_names'] ?? null,
            'responsible_org'    => $data['responsible_org'] ?? null,
            'priority'           => $data['priority'] ?? null,
            'target_date'        => $data['target_date'] ?? null,
            'remark'             => $data['remark'] ?? null,
            'notes'              => $data['notes'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('responsible_participant_id', $data)) {
            $changes['responsible_participant_id'] = $this->resolveParticipant($action->meeting, $data['responsible_participant_id']);
        }

        $action->update($changes);
        $action->meeting?->recordAudit('action_updated', $actor, "Action {$action->action_ref} updated", ['action_id' => $action->id]);

        return $action->fresh(['responsible:id,name', 'verifier:id,name']);
    }

    /**
     * Move an action along its lifecycle. Closing requires evidence (a file) or a
     * verification note (Rule 12); reopening clears the closure stamps.
     */
    public function progress(PurchaseMomActionItem $action, array $data, User $actor): PurchaseMomActionItem
    {
        $to = $data['status'] ?? null;

        if (! ActionStatus::canTransition($action->status, $to)) {
            throw new BusinessException(
                'Cannot move a '.ActionStatus::label($action->status).' action to '.ActionStatus::label($to).'.'
            );
        }

        $changes = ['status' => $to];

        // Optional evidence upload accompanying any progress step.
        $evidence = $data['evidence'] ?? null;
        if ($evidence instanceof UploadedFile) {
            $changes['evidence_path'] = $this->storeEvidence($action, $evidence);
        }
        if (array_key_exists('verification_note', $data) && $data['verification_note'] !== null) {
            $changes['verification_note'] = $data['verification_note'];
        }

        if ($to === ActionStatus::CLOSED) {
            $hasEvidence = ($changes['evidence_path'] ?? $action->evidence_path)
                || trim((string) ($changes['verification_note'] ?? $action->verification_note)) !== '';
            if (! $hasEvidence) {
                throw new BusinessException('Closing an action needs evidence or a verification note (Rule 12).');
            }
            $changes['closed_at']   = now();
            $changes['verified_at'] = now();
            $changes['verified_by'] = $actor->id;
        }

        if ($to === ActionStatus::REOPENED) {
            $changes['closed_at']   = null;
            $changes['verified_at'] = null;
            $changes['verified_by'] = null;
        }

        $action->update($changes);
        $action->meeting?->recordAudit('action_progressed', $actor, "Action {$action->action_ref} → ".ActionStatus::label($to), [
            'action_id' => $action->id,
        ]);
        Log::channel('purchase')->info('Purchase MOM action progressed', [
            'action_id' => $action->id, 'status' => $to, 'actor_id' => $actor->id,
        ]);

        return $action->fresh(['responsible:id,name', 'verifier:id,name']);
    }

    public function delete(PurchaseMomActionItem $action, User $actor): void
    {
        if ($action->evidence_path && Storage::disk(self::DISK)->exists($action->evidence_path)) {
            Storage::disk(self::DISK)->delete($action->evidence_path);
        }
        $action->meeting?->recordAudit('action_deleted', $actor, "Action {$action->action_ref} deleted", ['action_id' => $action->id]);
        $action->delete();
    }

    /** The stored evidence file for download (or null if none). */
    public function evidenceFile(PurchaseMomActionItem $action): ?array
    {
        if (! $action->evidence_path || ! Storage::disk(self::DISK)->exists($action->evidence_path)) {
            return null;
        }

        $path = Storage::disk(self::DISK)->path($action->evidence_path);

        return [
            'path'     => $path,
            'filename' => 'action-'.($action->action_ref ?: $action->id).'-evidence.'.pathinfo($path, PATHINFO_EXTENSION),
            'mime'     => mime_content_type($path) ?: 'application/octet-stream',
        ];
    }

    /* ── internals ─────────────────────────────────────────────── */

    /** Rule 11 — an action must name who owns it (a participant or free-text). */
    private function assertOwner(array $data): void
    {
        $hasParticipant = ! empty($data['responsible_participant_id']);
        $hasNames       = trim((string) ($data['responsible_names'] ?? '')) !== '';
        if (! $hasParticipant && ! $hasNames) {
            throw new BusinessException('Every action needs an owner — pick a participant or type a name (Rule 11).');
        }
    }

    /** Verify a chosen participant belongs to this meeting; null clears it. */
    private function resolveParticipant(PurchaseKickoffMeeting $meeting, $participantId): ?int
    {
        if (empty($participantId)) {
            return null;
        }
        $exists = PurchaseKickoffParticipant::where('purchase_kickoff_meeting_id', $meeting->id)
            ->whereKey($participantId)->exists();
        if (! $exists) {
            throw new BusinessException('The selected owner is not a participant of this meeting.');
        }

        return (int) $participantId;
    }

    private function storeEvidence(PurchaseMomActionItem $action, UploadedFile $file): string
    {
        // Remove the prior evidence so it isn't orphaned.
        if ($action->evidence_path && Storage::disk(self::DISK)->exists($action->evidence_path)) {
            Storage::disk(self::DISK)->delete($action->evidence_path);
        }
        $name = 'evidence-'.Str::random(12).'.'.$file->getClientOriginalExtension();

        return $file->storeAs(
            "tenant-{$action->tenant_id}/meeting-{$action->purchase_kickoff_meeting_id}/actions",
            $name,
            self::DISK
        );
    }

    /** ACT-YYYY-NNNN, sequential per tenant per year. */
    private function nextActionRef(int $tenantId): string
    {
        $year = now()->year;
        $count = PurchaseMomActionItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('action_ref', 'like', "ACT-{$year}-%")
            ->count();

        return sprintf('ACT-%d-%04d', $year, $count + 1);
    }
}
