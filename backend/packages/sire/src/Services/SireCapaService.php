<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\CorrectiveAction;
use Sire\Models\RecurrenceGroup;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — corrective and preventive actions.
 *
 * A CAPA hangs off EITHER an issue or a recurrence group, never both and never
 * neither. Attached to a recurrence group it survives the closure of any single
 * occurrence, which is the point: the pattern is what needs fixing.
 *
 * Verification is a separate step from completion, and deliberately cannot be
 * done by the person who completed the action. A preventive action nobody checked
 * is a preventive action nobody did.
 */
class SireCapaService
{
    public function __construct(private readonly SireAccessService $access)
    {
    }

    public function create(Model $subject, array $data, SireUserIdentity $actor): CorrectiveAction
    {
        [$reportId, $groupId, $tenantId] = $this->subjectKeys($subject);

        if (! in_array($data['action_type'], CorrectiveAction::TYPES, true)) {
            throw new SireException('Unknown action type.');
        }

        return DB::transaction(function () use ($subject, $data, $actor, $reportId, $groupId, $tenantId) {
            $action = CorrectiveAction::create([
                'tenant_id'           => $tenantId,      // from the subject, never ambient
                'report_id'           => $reportId,
                'recurrence_group_id' => $groupId,
                'action_type'         => $data['action_type'],
                'title'               => $data['title'],
                'description'         => $data['description'] ?? null,
                'owner_id'            => $data['owner_id'] ?? null,
                'due_at'              => $data['due_at'] ?? null,
                'status'              => 'open',
            ]);

            $subject->recordAudit(
                sprintf('%s action raised: %s', ucfirst($data['action_type']), $data['title']),
                $actor,
                null,
                ['action' => 'capa_created', 'capa_id' => $action->id, 'system' => true],
            );

            return $action;
        });
    }

    public function start(CorrectiveAction $action, SireUserIdentity $actor): CorrectiveAction
    {
        if ($action->status !== 'open') {
            throw new SireException('Only an open action can be started.');
        }

        $action->fill(['status' => 'in_progress', 'started_at' => now()])->save();
        $action->recordAudit('Action started', $actor, null, ['system' => true]);

        return $action;
    }

    public function complete(CorrectiveAction $action, string $note, SireUserIdentity $actor): CorrectiveAction
    {
        if (! in_array($action->status, ['open', 'in_progress'], true)) {
            throw new SireException('That action is already closed.');
        }
        if (trim($note) === '') {
            throw new SireException('Say what was done — an action completed with no note cannot be verified.');
        }

        $action->fill([
            'status'          => 'completed',
            'completed_at'    => now(),
            'completion_note' => $note,
        ])->save();

        $action->recordAudit('Action completed', $actor, $note, ['system' => true]);

        return $action;
    }

    /**
     * Verification closes the loop. Separation of duties is enforced rather than
     * suggested: the person who did the work is not the person who confirms it
     * worked.
     */
    public function verify(CorrectiveAction $action, array $data, SireUserIdentity $actor): CorrectiveAction
    {
        $this->access->assert($actor, 'sire.capa.verify');

        if ($action->status !== 'completed') {
            throw new SireException('Only a completed action can be verified.');
        }

        $completedBy = $action->auditLogs()->where('action', 'Action completed')->latest()->first()?->actor_id;
        if ($completedBy !== null && (int) $completedBy === (int) $actor->id) {
            throw new SireException(
                'Someone other than the person who completed this action needs to verify it.',
            );
        }

        if (! in_array($data['effectiveness'] ?? null, CorrectiveAction::EFFECTIVENESS, true)) {
            throw new SireException('Record whether the action was effective, partial or ineffective.');
        }

        $action->fill([
            'status'            => 'verified',
            'verified_by'       => $actor->id,
            'verified_at'       => now(),
            'verification_note' => $data['verification_note'] ?? null,
            'effectiveness'     => $data['effectiveness'],
        ])->save();

        $action->recordAudit(
            "Action verified — {$data['effectiveness']}",
            $actor,
            $data['verification_note'] ?? null,
            ['system' => true],
        );

        return $action;
    }

    public function cancel(CorrectiveAction $action, string $reason, SireUserIdentity $actor): CorrectiveAction
    {
        if ($action->status === 'verified') {
            throw new SireException('A verified action cannot be cancelled.');
        }

        $action->fill(['status' => 'cancelled'])->save();
        $action->recordAudit('Action cancelled', $actor, $reason, ['system' => true]);

        return $action;
    }

    /**
     * A recurrence group with open CAPA is not finished, whatever its risk score
     * says. Used to gate closing the group.
     */
    public function openCountFor(RecurrenceGroup $group): int
    {
        return CorrectiveAction::query()
            ->forTenant($group->tenant_id)
            ->where('recurrence_group_id', $group->id)
            ->open()
            ->count();
    }

    /** @return array{0: ?int, 1: ?int, 2: int} */
    private function subjectKeys(Model $subject): array
    {
        if ($subject instanceof Report) {
            return [$subject->id, null, (int) $subject->tenant_id];
        }
        if ($subject instanceof RecurrenceGroup) {
            return [null, $subject->id, (int) $subject->tenant_id];
        }

        throw new SireException('A CAPA must belong to an issue or a recurrence group.');
    }
}
