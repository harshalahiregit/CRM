<?php

namespace App\Services\Hr\Approval;

use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Support\Hr\Approval\ApproverType;

/**
 * Who is this rung waiting on?
 *
 * Returns user ids, never a boolean — the engine needs the set both to check
 * whether the actor is in it and to tell an administrator who a blocked request
 * is stuck on.
 *
 * Every query is tenant-scoped. A role or a user from another workspace can
 * never resolve, even if its id is stored on the step, because a tenant id that
 * does not match simply returns nothing rather than an error — which is the
 * right shape: the step is unresolvable, and the request blocks.
 */
class ApproverResolver
{
    /**
     * @return array{user_ids: int[], describe: string}
     */
    public function resolve(array $step, int $tenantId, ?int $employeeId): array
    {
        $type = $step['approver_type'] ?? null;

        return match ($type) {
            ApproverType::REPORTING_MANAGER =>
                $this->reportingManager($tenantId, $employeeId, (int) ($step['levels_up'] ?? 1)),
            ApproverType::STAFF_ROLE =>
                $this->staffRole($tenantId, $step['approver_ref'] ?? null),
            ApproverType::SPECIFIC_USER =>
                $this->specificUser($tenantId, $step['approver_ref'] ?? null),
            ApproverType::LEGACY_HR_QUEUE =>
                $this->legacyHrQueue($tenantId),
            default =>
                ['user_ids' => [], 'describe' => 'Unknown approver type'],
        };
    }

    /**
     * Walk the reporting line.
     *
     * levels_up = 1 is the employee's own manager. Each hop is re-read inside
     * the tenant, so a reporting_manager_id pointing at another workspace —
     * which should not happen, but is worth not trusting — ends the walk rather
     * than crossing the boundary.
     *
     * A cycle in the reporting chart would otherwise loop forever, so the walk
     * is bounded and remembers where it has been.
     */
    private function reportingManager(int $tenantId, ?int $employeeId, int $levelsUp): array
    {
        if (! $employeeId) {
            return ['user_ids' => [], 'describe' => 'No employee on the request'];
        }

        $levelsUp = max(1, min($levelsUp, 10));
        $seen     = [];
        $current  = HrEmployee::where('tenant_id', $tenantId)
            ->where('id', $employeeId)
            ->first(['id', 'reporting_manager_id']);

        for ($i = 0; $i < $levelsUp; $i++) {
            if (! $current || ! $current->reporting_manager_id) {
                return [
                    'user_ids' => [],
                    'describe' => $i === 0
                        ? 'The employee has no reporting manager'
                        : 'The reporting line ends before level '.$levelsUp,
                ];
            }

            if (isset($seen[$current->reporting_manager_id])) {
                return ['user_ids' => [], 'describe' => 'The reporting line loops back on itself'];
            }
            $seen[$current->reporting_manager_id] = true;

            $current = HrEmployee::where('tenant_id', $tenantId)
                ->where('id', $current->reporting_manager_id)
                ->first(['id', 'reporting_manager_id', 'user_id', 'name']);
        }

        if (! $current || ! $current->user_id) {
            return [
                'user_ids' => [],
                'describe' => 'The reporting manager has no login linked to their employee record',
            ];
        }

        return [
            'user_ids' => [(int) $current->user_id],
            'describe' => 'Reporting manager'.($levelsUp > 1 ? " (level {$levelsUp})" : ''),
        ];
    }

    /**
     * Everyone holding a staff role.
     *
     * Reads staff_roles through the live role architecture. Deliberately not
     * the retired access_roles table, and deliberately not an internal_role
     * string — a role created in HR Settings could never hold one of those
     * however it was configured, which is the whole reason this type exists.
     */
    private function staffRole(int $tenantId, $roleId): array
    {
        if (! $roleId) {
            return ['user_ids' => [], 'describe' => 'No role configured on this step'];
        }

        $ids = User::where('tenant_id', $tenantId)
            ->where('staff_role_id', (int) $roleId)
            ->where('status', 'active')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'user_ids' => $ids,
            'describe' => $ids === []
                ? 'No active user holds the configured role'
                : 'Staff role',
        ];
    }

    private function specificUser(int $tenantId, $userId): array
    {
        if (! $userId) {
            return ['user_ids' => [], 'describe' => 'No user configured on this step'];
        }

        $user = User::where('tenant_id', $tenantId)
            ->where('id', (int) $userId)
            ->where('status', 'active')
            ->first(['id']);

        return [
            'user_ids' => $user ? [(int) $user->id] : [],
            'describe' => $user ? 'Named approver' : 'The configured approver is no longer active',
        ];
    }

    /**
     * The compatibility rung.
     *
     * Not a user list — canManageHrQueue() is a predicate over several different
     * things (admin, HR executive, two internal_role values, a permission), and
     * enumerating everyone it admits would mean reimplementing it here and
     * drifting from it. The engine special-cases this type and asks the
     * predicate directly, which is what keeps day-one behaviour identical.
     */
    private function legacyHrQueue(int $tenantId): array
    {
        return ['user_ids' => [], 'describe' => 'Anyone who may manage the HR queue'];
    }
}
