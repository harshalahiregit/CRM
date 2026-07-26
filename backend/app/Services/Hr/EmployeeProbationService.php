<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationPolicy;
use App\Models\User;
use App\Repositories\Hr\EmployeeProbationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Employee Probation (Probation Phase 2). Assigns an employee to a Probation
 * Policy (auto-selected from grade/designation/department when not supplied),
 * deriving the type, dates and review cycle. One open probation per employee;
 * confirmed employees cannot be re-probated. Lifecycle here: Assigned → Active,
 * with Cancel from any open state (Extend/Confirm/Fail arrive in later phases).
 * Tenant-scoped, audited.
 */
class EmployeeProbationService
{
    public function __construct(private EmployeeProbationRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->list($tenantId, $f)->map(fn ($p) => $this->present($p))->all(),
            'stats' => $this->repo->stats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->find($id, $tenantId);
        $probation->recordAudit('Probation Viewed', $actor);

        return $this->present($probation, true);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($p) => $this->present($p, true))->all();
    }

    /* ── Assign ───────────────────────────────────────────── */

    public function assign(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee((int) ($data['employee_id'] ?? 0), $tenantId);

        $blocking = $this->repo->blockingProbation($employee->id, $tenantId);
        if ($blocking) {
            $msg = $blocking->current_status === HrEmployeeProbation::CONFIRMED
                ? 'This employee is already confirmed and cannot be placed on probation again.'
                : 'This employee already has an active probation.';
            throw new BusinessException($msg);
        }

        $policy = $this->resolvePolicy($data, $employee, $tenantId);
        $policy->loadMissing('probationType');
        $type = $policy->probationType;
        if (! $type) {
            throw new BusinessException('The selected probation policy has no probation type configured.');
        }

        $start = Carbon::parse($data['probation_start_date'] ?? $employee->joining_date ?? now()->toDateString());
        $duration = (int) ($type->default_duration_days ?: 90);
        $end = ! empty($data['probation_end_date']) ? Carbon::parse($data['probation_end_date']) : $start->copy()->addDays($duration);
        if ($end->lt($start)) {
            throw new BusinessException('Probation end date cannot be before the start date.');
        }

        $probation = HrEmployeeProbation::create([
            'tenant_id'            => $tenantId,
            'employee_id'          => $employee->id,
            'probation_policy_id'  => $policy->id,
            'probation_type_id'    => $type->id,
            'joining_date'         => $employee->joining_date ? Carbon::parse($employee->joining_date)->toDateString() : null,
            'probation_start_date' => $start->toDateString(),
            'probation_end_date'   => $end->toDateString(),
            'confirmation_due_date'=> $end->toDateString(),
            'current_status'       => HrEmployeeProbation::ASSIGNED,
            'review_cycle'         => $policy->review_frequency,
            'extension_count'      => 0,
            'remarks'              => $data['remarks'] ?? null,
            'assigned_by'          => $actor?->id,
            'created_by'           => $actor?->id,
            'updated_by'           => $actor?->id,
        ]);
        $probation->recordAudit('Probation Assigned', $actor, $data['remarks'] ?? null, ['employee' => $employee->name, 'policy' => $policy->name]);
        $this->log('Probation assigned', $tenantId, $probation->id);

        return $this->present($this->find($probation->id, $tenantId), true);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->find($id, $tenantId);
        $this->assertOpen($probation);

        $attrs = ['updated_by' => $actor?->id];
        if (array_key_exists('remarks', $data)) {
            $attrs['remarks'] = $data['remarks'];
        }
        if (array_key_exists('review_cycle', $data) && $data['review_cycle']) {
            $attrs['review_cycle'] = $data['review_cycle'];
        }
        $start = ! empty($data['probation_start_date']) ? Carbon::parse($data['probation_start_date']) : $probation->probation_start_date;
        $end = ! empty($data['probation_end_date']) ? Carbon::parse($data['probation_end_date']) : $probation->probation_end_date;
        if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw new BusinessException('Probation end date cannot be before the start date.');
        }
        if (! empty($data['probation_start_date'])) {
            $attrs['probation_start_date'] = Carbon::parse($start)->toDateString();
        }
        if (! empty($data['probation_end_date'])) {
            $attrs['probation_end_date'] = Carbon::parse($end)->toDateString();
            $attrs['confirmation_due_date'] = Carbon::parse($end)->toDateString();
        }

        $probation->update($attrs);
        $probation->recordAudit('Probation Updated', $actor);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function activate(int $id, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->find($id, $tenantId);
        if ($probation->current_status !== HrEmployeeProbation::ASSIGNED) {
            throw new BusinessException('Only an assigned probation can be activated.');
        }
        $probation->update(['current_status' => HrEmployeeProbation::ACTIVE, 'updated_by' => $actor?->id]);
        $probation->recordAudit('Probation Activated', $actor);
        $this->log('Probation activated', $tenantId, $probation->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function cancel(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $probation = $this->find($id, $tenantId);
        if (in_array($probation->current_status, HrEmployeeProbation::TERMINAL, true)) {
            throw new BusinessException("A {$probation->current_status} probation cannot be cancelled.");
        }
        $probation->update([
            'current_status' => HrEmployeeProbation::CANCELLED,
            'remarks'        => $data['remarks'] ?? $probation->remarks,
            'updated_by'     => $actor?->id,
        ]);
        $probation->recordAudit('Probation Cancelled', $actor, $data['remarks'] ?? null);
        $this->log('Probation cancelled', $tenantId, $probation->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Helpers ──────────────────────────────────────────── */

    private function assertOpen(HrEmployeeProbation $probation): void
    {
        if (in_array($probation->current_status, HrEmployeeProbation::TERMINAL, true)) {
            throw new BusinessException("A {$probation->current_status} probation is read-only and cannot be edited.");
        }
    }

    private function resolvePolicy(array $data, HrEmployee $employee, int $tenantId): HrProbationPolicy
    {
        if (! empty($data['probation_policy_id'])) {
            $policy = HrProbationPolicy::where('tenant_id', $tenantId)->find($data['probation_policy_id']);
            if (! $policy) {
                throw new BusinessException('Selected probation policy is invalid.');
            }

            return $policy;
        }
        // Auto-select from the employee's Organization Setup placement.
        $policy = $this->repo->policyForEmployee($tenantId, $employee->grade_id, $employee->designation_id, $employee->department_id);
        if (! $policy) {
            throw new BusinessException('No probation policy matches this employee — pick one manually.');
        }

        return $policy;
    }

    private function present(HrEmployeeProbation $p, bool $full = false): array
    {
        $end = $p->probation_end_date;
        $remaining = null;
        if ($end && in_array($p->current_status, HrEmployeeProbation::OPEN, true)) {
            $remaining = Carbon::today()->diffInDays(Carbon::parse($end), false);
        }
        $out = [
            'id' => $p->id,
            'employee_id' => $p->employee_id,
            'employee_name' => $p->employee?->name, 'employee_code' => $p->employee?->employee_code,
            'department' => $p->employee?->department, 'designation' => $p->employee?->designation,
            'probation_policy_id' => $p->probation_policy_id, 'policy' => $p->policy?->name,
            'probation_type_id' => $p->probation_type_id, 'probation_type' => $p->probationType?->name,
            'joining_date' => optional($p->joining_date)->toDateString(),
            'probation_start_date' => optional($p->probation_start_date)->toDateString(),
            'probation_end_date' => optional($p->probation_end_date)->toDateString(),
            'confirmation_due_date' => optional($p->confirmation_due_date)->toDateString(),
            'current_status' => $p->current_status,
            'review_cycle' => $p->review_cycle,
            'extension_count' => (int) $p->extension_count,
            'remaining_days' => $remaining,
            'remarks' => $p->remarks,
        ];
        if ($full) {
            $out['timeline'] = $p->relationLoaded('auditLogs')
                ? $p->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrEmployeeProbation
    {
        $probation = $this->repo->find($id, $tenantId);
        if (! $probation) {
            throw new BusinessException('Probation record not found', 404);
        }

        return $probation;
    }

    private function employee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
