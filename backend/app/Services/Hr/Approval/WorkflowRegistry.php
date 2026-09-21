<?php

namespace App\Services\Hr\Approval;

use App\Models\Hr\HrApprovalWorkflow;
use App\Support\Hr\Approval\ApproverType;

/**
 * Which ladder applies to this process, in this tenant, right now.
 *
 * The important behaviour here is what happens when there ISN'T one. A tenant
 * that has configured nothing, or has deliberately disabled its workflow, must
 * keep approving leave exactly as it did yesterday — so the registry
 * synthesises a single legacy rung rather than returning an empty ladder.
 *
 * An empty ladder would be the dangerous answer twice over: read as "no steps
 * required" it approves without anybody, and read as "no approver" it deadlocks
 * every pending request in the company.
 */
class WorkflowRegistry
{
    /** The active configured workflow, or null. */
    public function activeFor(int $tenantId, string $process): ?HrApprovalWorkflow
    {
        return HrApprovalWorkflow::forTenant($tenantId)
            ->where('process', $process)
            ->where('is_active', true)
            ->with('activeSteps')
            ->first();
    }

    /** Configured or not, active or not — for the Settings screen. */
    public function anyFor(int $tenantId, string $process): ?HrApprovalWorkflow
    {
        return HrApprovalWorkflow::forTenant($tenantId)
            ->where('process', $process)
            ->with('steps')
            ->first();
    }

    /**
     * The steps a NEW request should snapshot.
     *
     * Conditions are applied here, at snapshot time, against the request's own
     * facts: a rung that does not apply to this employee is left out of the
     * ladder entirely rather than being carried along and skipped later. That
     * keeps "which step am I on" a simple ordinal rather than a filter that
     * could give a different answer on a later read.
     *
     * @return array{steps: array, workflow: ?HrApprovalWorkflow}
     */
    public function resolveSteps(int $tenantId, string $process, array $context): array
    {
        $workflow = $this->activeFor($tenantId, $process);

        if (! $workflow) {
            return ['steps' => [$this->legacyStep()], 'workflow' => null];
        }

        $evaluator = app(ConditionEvaluator::class);
        $steps     = [];
        $order     = 1;

        foreach ($workflow->activeSteps as $step) {
            $snapshot = $step->toSnapshot();

            if (! $evaluator->matches($snapshot['conditions'], $context)) {
                continue;
            }

            // Renumbered contiguously so current_step is always 1..n over the
            // rungs that actually apply.
            $snapshot['step_order'] = $order++;
            $steps[] = $snapshot;
        }

        // Configured, active, but every rung filtered out by its conditions.
        // Falling through to the legacy step keeps the request decidable
        // instead of stranding it — a workflow that excludes everybody should
        // not be able to freeze the queue.
        if ($steps === []) {
            return ['steps' => [$this->legacyStep()], 'workflow' => $workflow];
        }

        return ['steps' => $steps, 'workflow' => $workflow];
    }

    /**
     * The single rung that reproduces today's behaviour.
     *
     * Its approver type is checked by the engine against canManageHrQueue()
     * itself, so this is not an approximation of the current gate — it is the
     * current gate.
     */
    public function legacyStep(): array
    {
        return [
            'step_order'    => 1,
            'name'          => 'HR approval',
            'approver_type' => ApproverType::LEGACY_HR_QUEUE,
            'approver_ref'  => null,
            'levels_up'     => 1,
            'conditions'    => [],
        ];
    }
}
