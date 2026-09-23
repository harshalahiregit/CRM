<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveBalanceTransaction;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\User;
use App\Repositories\Hr\EmployeeLeaveBalanceRepository;
use App\Support\Hr\LeaveCarryForward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Employee Leave Balance & Allocation (Leave Phase 2).
 *
 * available_balance = opening + allocated + adjusted + carried_forward − used.
 * Assigning a policy auto-creates balances for every mapped leave type, carries
 * forward the prior available (capped by the policy's limit), and deactivates the
 * previous active balances (single active per employee + type; history preserved).
 * Every change appends an immutable ledger transaction — values are never
 * overwritten. No leave application/approval logic here.
 */
class EmployeeLeaveBalanceService
{
    public function __construct(private EmployeeLeaveBalanceRepository $repo)
    {
    }

    /**
     * The admin balance grid.
     *
     * Only this listing takes the scope. activeByType / allActiveForEmployee /
     * activeForEmployee stay unscoped on purpose: they are the leave APPLY and
     * DEDUCT path, reached by the employee's own request and by the Attendance
     * App, and a balance has to be debited whoever triggered the leave.
     */
    public function list(int $tenantId, array $f, ?User $actor = null): array
    {
        return [
            'data'  => $this->repo->balances($tenantId, $f, $actor)->map(fn ($b) => $this->present($b))->all(),
            'stats' => $this->repo->stats($tenantId, $actor),
        ];
    }

    /**
     * Active balances + current policy for one employee.
     *
     * The guard sits here rather than on activeForEmployee(), because that
     * repository method is shared with the leave apply/deduct path, which has
     * to run whoever triggered the leave. This is the admin read surface, where
     * the employee id arrives in the URL, so it takes the scope assertion.
     */
    public function forEmployee(int $employeeId, int $tenantId, ?User $actor = null): array
    {
        $this->employee($employeeId, $tenantId);
        app(\App\Services\Auth\ScopeResolver::class)->assertCanActOnEmployee($actor, $employeeId);

        $balances = $this->repo->activeForEmployee($employeeId, $tenantId);
        $policy = $balances->first(fn ($b) => $b->policy)?->policy;

        return [
            'employee_id'    => $employeeId,
            'current_policy' => $policy ? ['id' => $policy->id, 'name' => $policy->name] : null,
            'balances'       => $balances->map(fn ($b) => $this->present($b))->all(),
        ];
    }

    /**
     * Assign a leave policy: deactivate any current active balances, then create a
     * fresh active balance for each mapped leave type (allocation + carry forward).
     */
    public function assignPolicy(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee((int) $data['employee_id'], $tenantId);
        $policy = HrLeavePolicy::where('tenant_id', $tenantId)
            ->with('policyTypes.leaveType')
            ->find($data['leave_policy_id']);
        if (! $policy) {
            throw new BusinessException('Leave policy not found', 404);
        }
        if ($policy->policyTypes->isEmpty()) {
            throw new BusinessException('This policy has no mapped leave types to allocate.');
        }

        $effectiveFrom = $data['effective_from'] ?? now()->toDateString();

        DB::transaction(function () use ($employee, $policy, $tenantId, $effectiveFrom, $actor) {
            // Carry-forward source = prior active available per leave type.
            $prior = $this->repo->allActiveForEmployee($employee->id, $tenantId)->keyBy('leave_type_id');

            // Previous policy/balances become inactive — history preserved.
            foreach ($prior as $b) {
                $b->update(['status' => HrEmployeeLeaveBalance::INACTIVE, 'effective_to' => $effectiveFrom, 'updated_by' => $actor?->id]);
            }

            foreach ($policy->policyTypes as $pt) {
                if (! $pt->leaveType) {
                    continue;
                }
                $allocated = (float) $pt->yearly_allocation;
                $prev = $prior->get($pt->leave_type_id);

                // The leave TYPE's own configuration was ignored here: a type
                // marked "does not carry forward" carried forward anyway, and
                // its max_carry_forward — printed on the Leave Types screen as
                // "≤ N" — was never a ceiling. LeaveCarryForward reconciles
                // that with the policy's limit in one place.
                $cf = $prev
                    ? LeaveCarryForward::forBalance($pt->leaveType, $pt, (float) $prev->available_balance)
                    : 0.0;

                $balance = HrEmployeeLeaveBalance::create([
                    'tenant_id' => $tenantId, 'employee_id' => $employee->id,
                    'leave_policy_id' => $policy->id, 'leave_type_id' => $pt->leave_type_id,
                    'allocated' => $allocated, 'opening_balance' => 0, 'used' => 0, 'adjusted' => 0,
                    'carried_forward' => $cf, 'available_balance' => round($allocated + $cf, 1),
                    'effective_from' => $effectiveFrom, 'status' => HrEmployeeLeaveBalance::ACTIVE,
                    'created_by' => $actor?->id, 'updated_by' => $actor?->id,
                ]);

                $this->txn($balance, 'Allocation', $allocated, "Policy “{$policy->name}” allocation", $actor);
                if ($cf > 0) {
                    $this->txn($balance, 'Carry Forward', $cf, 'Carried forward from previous policy', $actor);
                    $balance->recordAudit('Carry Forward Applied', $actor, null, ['leave_type_id' => $pt->leave_type_id, 'quantity' => $cf]);
                }
                $balance->recordAudit('Leave Allocated', $actor, null, ['leave_type_id' => $pt->leave_type_id, 'allocated' => $allocated]);
            }

            $policy->recordAudit('Policy Assigned', $actor, null, ['employee_id' => $employee->id, 'types' => $policy->policyTypes->count()]);
        });

        $this->log('Policy assigned', $tenantId, $employee->id);

        return $this->forEmployee($employee->id, $tenantId);
    }

    /**
     * Assign one policy to a whole group in a single action.
     *
     * Without this, "everyone in Sales moves to the new leave policy" meant HR
     * opening forty employees one at a time, and the department scope on a
     * policy was decoration -- it described who the policy was FOR but nothing
     * acted on it. Scope is explicit rather than read off the policy so an
     * admin can also roll a policy out to a subset, or to a hand-picked list.
     *
     * Each employee runs through the same assignPolicy() path, so carry
     * forward, the ledger transactions and the audit trail are identical to a
     * one-person assignment. One employee failing (no balances, a race) does
     * not abandon the rest -- the failures come back named, so whoever pressed
     * the button knows exactly who was missed instead of guessing from a count.
     */
    public function assignPolicyToMany(array $data, int $tenantId, ?User $actor = null): array
    {
        $policy = HrLeavePolicy::where('tenant_id', $tenantId)->find($data['leave_policy_id']);
        if (! $policy) {
            throw new BusinessException('Leave policy not found', 404);
        }

        $scope = $data['scope'] ?? 'all';
        $q = HrEmployee::where('tenant_id', $tenantId)->where('status', 'Active');

        switch ($scope) {
            case 'department':
                if (empty($data['department_id'])) {
                    throw new BusinessException('Choose a department.');
                }
                $q->where('department_id', (int) $data['department_id']);
                break;
            case 'designation':
                if (empty($data['designation_id'])) {
                    throw new BusinessException('Choose a designation.');
                }
                $q->where('designation_id', (int) $data['designation_id']);
                break;
            case 'grade':
                if (empty($data['grade_id'])) {
                    throw new BusinessException('Choose a grade.');
                }
                $q->where('grade_id', (int) $data['grade_id']);
                break;
            case 'employees':
                $ids = array_filter(array_map('intval', (array) ($data['employee_ids'] ?? [])));
                if ($ids === []) {
                    throw new BusinessException('Choose at least one employee.');
                }
                $q->whereIn('id', $ids);
                break;
            case 'all':
                break;
            default:
                throw new BusinessException('Unknown scope.');
        }

        $employees = $q->get(['id', 'name']);
        if ($employees->isEmpty()) {
            throw new BusinessException('No active employees match that selection.');
        }

        $assigned = 0;
        $failed = [];

        foreach ($employees as $employee) {
            try {
                $this->assignPolicy([
                    'employee_id'     => $employee->id,
                    'leave_policy_id' => $policy->id,
                    'effective_from'  => $data['effective_from'] ?? null,
                ], $tenantId, $actor);
                $assigned++;
            } catch (\Throwable $e) {
                $failed[] = ['employee_id' => $employee->id, 'name' => $employee->name, 'reason' => $e->getMessage()];
            }
        }

        $policy->recordAudit('Policy Bulk Assigned', $actor, null, [
            'scope' => $scope, 'matched' => $employees->count(), 'assigned' => $assigned, 'failed' => count($failed),
        ]);
        $this->log("Policy bulk assigned ({$scope}): {$assigned} of {$employees->count()}", $tenantId, $policy->id);

        return [
            'policy'   => ['id' => $policy->id, 'name' => $policy->name],
            'scope'    => $scope,
            'matched'  => $employees->count(),
            'assigned' => $assigned,
            'failed'   => $failed,
        ];
    }

    /** Manually allocate additional leave to an employee's active balance for a type. */
    public function allocate(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee((int) $data['employee_id'], $tenantId);
        $this->leaveType((int) $data['leave_type_id'], $tenantId);
        $qty = (float) $data['quantity'];
        if ($qty <= 0) {
            throw new BusinessException('Allocation quantity must be greater than zero.');
        }

        $balance = $this->repo->activeByType($employee->id, (int) $data['leave_type_id'], $tenantId);
        if (! $balance) {
            $balance = HrEmployeeLeaveBalance::create([
                'tenant_id' => $tenantId, 'employee_id' => $employee->id, 'leave_type_id' => (int) $data['leave_type_id'],
                'status' => HrEmployeeLeaveBalance::ACTIVE, 'effective_from' => now()->toDateString(),
                'created_by' => $actor?->id, 'updated_by' => $actor?->id,
            ]);
        }
        $balance->allocated = (float) $balance->allocated + $qty;
        $balance->updated_by = $actor?->id;
        $balance->recomputeAvailable();
        $balance->save();

        $this->txn($balance, 'Allocation', $qty, $data['remarks'] ?? 'Manual allocation', $actor);
        $balance->recordAudit('Leave Allocated', $actor, null, ['quantity' => $qty, 'leave_type_id' => $balance->leave_type_id]);
        $this->log('Leave allocated', $tenantId, $balance->id);

        return $this->present($balance->fresh(['policy', 'leaveType', 'employee']));
    }

    /**
     * Adjust a balance up or down. A downward adjustment cannot push available
     * below zero unless the policy permits a negative balance. Never overwrites —
     * appends an Adjustment ledger entry.
     */
    public function adjust(array $data, int $tenantId, ?User $actor = null): array
    {
        $balance = $this->repo->findBalance((int) $data['balance_id'], $tenantId);
        if (! $balance) {
            throw new BusinessException('Leave balance not found', 404);
        }
        $qty = (float) $data['quantity'];
        if ($qty == 0.0) {
            throw new BusinessException('Adjustment quantity cannot be zero.');
        }

        $newAvailable = round(
            (float) $balance->opening_balance + (float) $balance->allocated + ((float) $balance->adjusted + $qty)
            + (float) $balance->carried_forward - (float) $balance->used,
            1
        );
        $negativeAllowed = (bool) ($balance->policy?->negative_balance_allowed ?? false);
        if ($newAvailable < 0 && ! $negativeAllowed) {
            throw new BusinessException('This adjustment would make the balance negative, which the policy does not allow.');
        }

        $balance->adjusted = (float) $balance->adjusted + $qty;
        $balance->updated_by = $actor?->id;
        $balance->recomputeAvailable();
        $balance->save();

        $this->txn($balance, 'Adjustment', $qty, $data['remarks'] ?? null, $actor);
        $balance->recordAudit('Leave Balance Adjusted', $actor, null, ['quantity' => $qty, 'available' => $balance->available_balance]);
        $this->log('Leave balance adjusted', $tenantId, $balance->id);

        return $this->present($balance->fresh(['policy', 'leaveType', 'employee']));
    }

    /**
     * Deduct approved leave from an employee's active balance (Phase 4 approval).
     * Increases `used`, recomputes available and appends one immutable Leave
     * Deduction ledger entry. Values are never overwritten. Returns the fresh balance.
     */
    public function recordUsage(int $employeeId, int $leaveTypeId, float $days, ?string $remarks, int $tenantId, ?User $actor = null): HrEmployeeLeaveBalance
    {
        $balance = $this->repo->activeByType($employeeId, $leaveTypeId, $tenantId);
        if (! $balance) {
            throw new BusinessException('No active leave balance to deduct from.');
        }
        $balance->loadMissing('policy');
        $available = (float) $balance->available_balance;
        $negativeAllowed = (bool) ($balance->policy?->negative_balance_allowed ?? false);
        if ($days > $available && ! $negativeAllowed) {
            throw new BusinessException("Insufficient balance: {$days} day(s) requested, {$available} available.");
        }

        $balance->used = (float) $balance->used + $days;
        $balance->updated_by = $actor?->id;
        $balance->recomputeAvailable();
        $balance->save();

        $this->txn($balance, 'Leave Deduction', -$days, $remarks, $actor);
        $balance->recordAudit('Leave Balance Deducted', $actor, null, ['quantity' => $days, 'available' => $balance->available_balance]);
        $this->log('Leave balance deducted', $tenantId, $balance->id);

        return $balance->fresh();
    }

    public function history(int $balanceId, int $tenantId, ?User $actor = null): array
    {
        $balance = $this->repo->findBalance($balanceId, $tenantId);
        if (! $balance) {
            throw new BusinessException('Leave balance not found', 404);
        }

        // The ledger is one employee's accrual and deduction history, so the
        // balance being readable is not enough — whose it is decides.
        app(\App\Services\Auth\ScopeResolver::class)->assertCanActOnEmployee($actor, $balance->employee_id);

        return [
            'balance' => $this->present($balance),
            'ledger'  => $this->repo->transactions($balanceId, $tenantId)->map(fn ($t) => [
                'id' => $t->id, 'transaction_type' => $t->transaction_type, 'quantity' => (float) $t->quantity,
                'remarks' => $t->remarks, 'created_by' => $t->created_by,
                'created_at' => optional($t->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    /* ── Helpers ──────────────────────────────────────────── */
    private function txn(HrEmployeeLeaveBalance $balance, string $type, float $qty, ?string $remarks, ?User $actor): void
    {
        HrLeaveBalanceTransaction::create([
            'tenant_id' => $balance->tenant_id,
            'employee_leave_balance_id' => $balance->id,
            'transaction_type' => $type, 'quantity' => $qty, 'remarks' => $remarks, 'created_by' => $actor?->id,
        ]);
    }

    private function present(HrEmployeeLeaveBalance $b): array
    {
        return [
            'id' => $b->id, 'employee_id' => $b->employee_id,
            'employee_name' => $b->employee?->name, 'employee_code' => $b->employee?->employee_code,
            'department' => $b->employee?->department,
            'leave_policy_id' => $b->leave_policy_id, 'policy_name' => $b->policy?->name,
            'leave_type_id' => $b->leave_type_id, 'leave_type' => $b->leaveType?->name,
            'leave_type_code' => $b->leaveType?->code, 'color' => $b->leaveType?->color,
            'allocated' => (float) $b->allocated, 'opening_balance' => (float) $b->opening_balance,
            'used' => (float) $b->used, 'adjusted' => (float) $b->adjusted,
            'carried_forward' => (float) $b->carried_forward, 'available_balance' => (float) $b->available_balance,
            'status' => $b->status,
            'effective_from' => optional($b->effective_from)->toDateString(),
            'transactions_count' => $b->transactions_count ?? $b->transactions()->count(),
        ];
    }

    private function employee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function leaveType(int $id, int $tenantId): void
    {
        if (! HrLeaveType::where('tenant_id', $tenantId)->where('id', $id)->exists()) {
            throw new BusinessException('Leave type is invalid for this tenant.');
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
