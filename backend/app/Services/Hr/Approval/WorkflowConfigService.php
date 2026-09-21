<?php

namespace App\Services\Hr\Approval;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrApprovalWorkflow;
use App\Models\Hr\HrApprovalWorkflowStep;
use App\Models\StaffRole;
use App\Models\User;
use App\Support\Hr\Approval\ApprovalProcess;
use App\Support\Hr\Approval\ApproverType;
use Illuminate\Support\Facades\DB;

/**
 * Reading and writing the configuration an administrator edits.
 *
 * Kept apart from ApprovalEngine on purpose: the engine decides requests and
 * must never write configuration, and this writes configuration and must never
 * decide a request. A single class doing both would make "changing a workflow"
 * and "approving something" reachable from the same place.
 *
 * Editing steps bumps the workflow version and touches no request. An approval
 * already under way carries its own snapshot and is unaffected — which is the
 * behaviour the version number records rather than the one it enforces.
 */
class WorkflowConfigService
{
    public function __construct(
        private WorkflowRegistry $registry,
        private ConditionEvaluator $conditions,
    ) {
    }

    /** Every process, whether or not it has been configured yet. */
    public function overview(int $tenantId): array
    {
        $out = [];

        foreach (ApprovalProcess::configurable() as $process) {
            $workflow = $this->registry->anyFor($tenantId, $process);

            $out[] = [
                'process'    => $process,
                'label'      => ApprovalProcess::label($process),
                'configured' => (bool) $workflow,
                'is_active'  => (bool) ($workflow?->is_active),
                'version'    => $workflow?->version,
                'step_count' => $workflow ? $workflow->steps->count() : 0,
                // What actually happens today when nothing is configured.
                'fallback'   => $workflow ? null : 'Anyone who may manage the HR queue',
            ];
        }

        return $out;
    }

    public function show(int $tenantId, string $process): array
    {
        $this->assertProcess($process);
        $workflow = $this->registry->anyFor($tenantId, $process);

        return [
            'process'   => $process,
            'label'     => ApprovalProcess::label($process),
            'workflow'  => $workflow ? [
                'id'        => $workflow->id,
                'name'      => $workflow->name,
                'is_active' => (bool) $workflow->is_active,
                'version'   => $workflow->version,
            ] : null,
            'steps'     => $workflow ? $workflow->steps->map(fn ($s) => [
                'id'            => $s->id,
                'step_order'    => $s->step_order,
                'name'          => $s->name,
                'approver_type' => $s->approver_type,
                'approver_label'=> ApproverType::label($s->approver_type),
                'approver_ref'  => $s->approver_ref,
                'levels_up'     => $s->levels_up,
                'conditions'    => $s->conditions ?: [],
                'is_active'     => (bool) $s->is_active,
            ])->values()->all() : [],
            // Everything the form needs to render itself, so the screen holds
            // no copy of the vocabulary.
            'options'   => $this->options($tenantId, $process),
        ];
    }

    public function options(int $tenantId, string $process): array
    {
        return [
            'approver_types' => array_map(
                fn ($t) => ['value' => $t, 'label' => ApproverType::label($t)],
                ApproverType::CONFIGURABLE
            ),
            'roles' => StaffRole::where('tenant_id', $tenantId)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($r) => ['value' => $r->id, 'label' => $r->name])->all(),
            'users' => User::where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('name')->limit(200)->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name.' ('.$u->email.')'])->all(),
            'conditions' => ApprovalProcess::conditionsFor($process),
            'supports_amount' => ApprovalProcess::amountFieldFor($process) !== null,
        ];
    }

    /**
     * Replace the ladder wholesale.
     *
     * Steps are rewritten rather than diffed: the payload IS the ladder, and a
     * partial merge would leave a removed rung behind. Existing requests are
     * untouched either way — they hold snapshots.
     */
    public function save(int $tenantId, string $process, array $data, ?User $actor = null): array
    {
        $this->assertProcess($process);

        $steps = $data['steps'] ?? [];
        if (! is_array($steps)) {
            throw new BusinessException('Steps must be a list.', 422);
        }
        if (count($steps) > 10) {
            throw new BusinessException('An approval ladder is limited to 10 steps.', 422);
        }

        $allowed = ApprovalProcess::conditionsFor($process);

        return DB::transaction(function () use ($tenantId, $process, $data, $steps, $allowed, $actor) {
            $workflow = HrApprovalWorkflow::forTenant($tenantId)->where('process', $process)->first();

            if ($workflow) {
                $workflow->update([
                    'name'       => $data['name'] ?? $workflow->name,
                    'is_active'  => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $workflow->is_active,
                    'version'    => $workflow->version + 1,
                    'updated_by' => $actor?->id,
                ]);
            } else {
                $workflow = HrApprovalWorkflow::create([
                    'tenant_id'  => $tenantId,
                    'process'    => $process,
                    'name'       => $data['name'] ?? (ApprovalProcess::label($process).' approval'),
                    'is_active'  => (bool) ($data['is_active'] ?? true),
                    'version'    => 1,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
            }

            HrApprovalWorkflowStep::where('workflow_id', $workflow->id)->delete();

            $order = 1;
            foreach ($steps as $step) {
                $type = $step['approver_type'] ?? null;

                if (! ApproverType::isConfigurable($type)) {
                    throw new BusinessException('Unknown approver type: '.(string) $type, 422);
                }

                $ref = $step['approver_ref'] ?? null;
                $this->assertRefBelongsToTenant($tenantId, $type, $ref);

                HrApprovalWorkflowStep::create([
                    'tenant_id'     => $tenantId,
                    'workflow_id'   => $workflow->id,
                    'step_order'    => $order++,
                    'name'          => $step['name'] ?? ApproverType::label($type),
                    'approver_type' => $type,
                    'approver_ref'  => $type === ApproverType::REPORTING_MANAGER ? null : ($ref ? (int) $ref : null),
                    'levels_up'     => max(1, min((int) ($step['levels_up'] ?? 1), 10)),
                    'conditions'    => $this->conditions->sanitise($step['conditions'] ?? [], $allowed),
                    'is_active'     => (bool) ($step['is_active'] ?? true),
                ]);
            }

            $workflow->recordAudit('Approval Workflow Saved', $actor, null, [
                'process' => $process, 'version' => $workflow->version, 'steps' => count($steps),
            ]);

            return $this->show($tenantId, $process);
        });
    }

    public function setStatus(int $tenantId, string $process, bool $active, ?User $actor = null): array
    {
        $this->assertProcess($process);

        $workflow = HrApprovalWorkflow::forTenant($tenantId)->where('process', $process)->first();
        if (! $workflow) {
            throw new BusinessException('No workflow is configured for this process.', 404);
        }

        $workflow->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $workflow->recordAudit($active ? 'Approval Workflow Enabled' : 'Approval Workflow Disabled', $actor);

        // Disabling does not strand anything: the registry falls back to the
        // legacy HR-queue rung, so new requests stay decidable.
        return $this->show($tenantId, $process);
    }

    /**
     * A role or user id from another workspace must never be storable.
     *
     * Checked on write rather than only at resolution time, so a
     * cross-tenant reference cannot be saved and then sit in the configuration
     * looking legitimate.
     */
    private function assertRefBelongsToTenant(int $tenantId, string $type, $ref): void
    {
        if ($type === ApproverType::REPORTING_MANAGER) {
            return;
        }

        if (! $ref) {
            throw new BusinessException(ApproverType::label($type).' steps need somebody selected.', 422);
        }

        $exists = $type === ApproverType::STAFF_ROLE
            ? StaffRole::where('tenant_id', $tenantId)->whereKey((int) $ref)->exists()
            : User::where('tenant_id', $tenantId)->whereKey((int) $ref)->exists();

        if (! $exists) {
            throw new BusinessException('That approver does not belong to this workspace.', 422);
        }
    }

    private function assertProcess(string $process): void
    {
        if (! ApprovalProcess::exists($process)) {
            throw new BusinessException('Unknown approval process: '.$process, 404);
        }

        // Registered but not configurable — advances, whose rungs are resolved
        // by AdvanceTierService rather than by a ladder anyone can edit.
        if (! ApprovalProcess::isConfigurable($process)) {
            throw new BusinessException(
                ApprovalProcess::label($process).' approvals are not configurable: their approvers are resolved from the reporting line and role.',
                422
            );
        }
    }
}
