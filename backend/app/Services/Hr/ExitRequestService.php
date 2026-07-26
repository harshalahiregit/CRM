<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExitPolicy;
use App\Models\Hr\HrExitRequest;
use App\Models\Hr\HrExitType;
use App\Models\User;
use App\Repositories\Hr\ExitRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Exit Requests (Exit Phase 2). An employee's separation record built on the
 * Phase 1 masters. Exit Type is required; the Exit Policy is auto-attached from
 * the employee's grade/designation/department (or supplied) and drives the
 * notice period. Notice End Date is computed from Request Date + policy notice
 * days; a manual override is honoured only when the policy allows buyout.
 * Lifecycle: Draft → Submitted, Withdrawn from either. Tenant-scoped, audited.
 */
class ExitRequestService
{
    public function __construct(private ExitRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'stats' => $this->repo->requestStats($tenantId),
            'rows'  => $this->repo->requests($tenantId, $f)->map(fn ($r) => $this->present($r))->all(),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        $request->recordAudit('Exit Request Viewed', $actor);

        return $this->present($request, true);
    }

    /** Read-only current exit for an employee (Employee Profile → Exit tab). */
    public function currentForEmployee(int $employeeId, int $tenantId): ?array
    {
        $this->employee($employeeId, $tenantId);
        $request = $this->repo->currentRequestForEmployee($employeeId, $tenantId);

        return $request ? $this->present($request, true) : null;
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee((int) ($data['employee_id'] ?? 0), $tenantId);
        $exitType = $this->exitType((int) ($data['exit_type_id'] ?? 0), $tenantId);
        $policy   = $this->resolvePolicy($data, $employee, $tenantId);

        $computed = $this->computeNotice($data, $policy, $exitType);

        $status = ($data['status'] ?? HrExitRequest::DRAFT) === HrExitRequest::SUBMITTED
            ? HrExitRequest::SUBMITTED : HrExitRequest::DRAFT;

        $request = HrExitRequest::create([
            'tenant_id'   => $tenantId,
            'employee_id' => $employee->id,
            'exit_type_id' => $exitType->id,
            'exit_policy_id' => $policy?->id,
            'request_date' => $computed['request_date'],
            'last_working_date' => $computed['last_working_date'],
            'notice_start_date' => $computed['notice_start_date'],
            'notice_end_date'   => $computed['notice_end_date'],
            'notice_days'       => $computed['notice_days'],
            'reason'            => $data['reason'] ?? null,
            'employee_remarks'  => $data['employee_remarks'] ?? null,
            'hr_remarks'        => $data['hr_remarks'] ?? null,
            'attachment_path'   => $data['attachment_path'] ?? null,
            'status'            => $status,
            'submitted_at'      => $status === HrExitRequest::SUBMITTED ? now() : null,
            'created_by'        => $actor?->id,
            'updated_by'        => $actor?->id,
        ]);

        $request->recordAudit(
            $status === HrExitRequest::SUBMITTED ? 'Exit Request Submitted' : 'Exit Request Created',
            $actor, null, ['employee' => $employee->name, 'type' => $exitType->name]
        );
        $this->log('Exit request created', $tenantId, $request->id);

        return $this->present($this->find($request->id, $tenantId), true);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        if (! in_array($request->status, [HrExitRequest::DRAFT, HrExitRequest::SUBMITTED], true)) {
            throw new BusinessException('Only a draft or submitted exit request can be edited.');
        }

        $employee = $request->employee ?? $this->employee($request->employee_id, $tenantId);
        $exitType = array_key_exists('exit_type_id', $data) && $data['exit_type_id']
            ? $this->exitType((int) $data['exit_type_id'], $tenantId)
            : ($request->exitType ?? $this->exitType($request->exit_type_id, $tenantId));

        $policy = array_key_exists('exit_policy_id', $data)
            ? $this->resolvePolicy($data, $employee, $tenantId)
            : $request->policy;

        // Merge current record with incoming dates so notice recomputes coherently.
        $merged = array_merge([
            'request_date'      => optional($request->request_date)->toDateString(),
            'last_working_date' => optional($request->last_working_date)->toDateString(),
            'notice_start_date' => optional($request->notice_start_date)->toDateString(),
            'notice_end_date'   => optional($request->notice_end_date)->toDateString(),
            'notice_days'       => $request->notice_days,
        ], array_filter($data, fn ($v) => $v !== null && $v !== ''));

        $computed = $this->computeNotice($merged, $policy, $exitType);

        $request->update([
            'exit_type_id'  => $exitType->id,
            'exit_policy_id' => $policy?->id,
            'request_date'  => $computed['request_date'],
            'last_working_date' => $computed['last_working_date'],
            'notice_start_date' => $computed['notice_start_date'],
            'notice_end_date'   => $computed['notice_end_date'],
            'notice_days'       => $computed['notice_days'],
            'reason'            => array_key_exists('reason', $data) ? $data['reason'] : $request->reason,
            'employee_remarks'  => array_key_exists('employee_remarks', $data) ? $data['employee_remarks'] : $request->employee_remarks,
            'hr_remarks'        => array_key_exists('hr_remarks', $data) ? $data['hr_remarks'] : $request->hr_remarks,
            'attachment_path'   => $data['attachment_path'] ?? $request->attachment_path,
            'updated_by'        => $actor?->id,
        ]);
        $request->recordAudit('Exit Request Updated', $actor, null, ['type' => $exitType->name]);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function submit(int $id, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        if ($request->status !== HrExitRequest::DRAFT) {
            throw new BusinessException('Only a draft exit request can be submitted.');
        }
        $request->update(['status' => HrExitRequest::SUBMITTED, 'submitted_at' => now(), 'updated_by' => $actor?->id]);
        $request->recordAudit('Exit Request Submitted', $actor);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function withdraw(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $request = $this->find($id, $tenantId);
        if (! in_array($request->status, [HrExitRequest::DRAFT, HrExitRequest::SUBMITTED], true)) {
            throw new BusinessException('Only a draft or submitted exit request can be withdrawn.');
        }
        $request->update([
            'status'       => HrExitRequest::WITHDRAWN,
            'withdrawn_at' => now(),
            'hr_remarks'   => $data['hr_remarks'] ?? $request->hr_remarks,
            'updated_by'   => $actor?->id,
        ]);
        $request->recordAudit('Exit Request Withdrawn', $actor, $data['reason'] ?? null);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Notice period ────────────────────────────────────── */

    /**
     * Resolve request/last-working/notice dates. Notice End = Notice Start +
     * notice days (policy → else exit-type default → else supplied). A manually
     * supplied Notice End is honoured only when the policy allows buyout (or when
     * there is no policy); otherwise it is recomputed. Notice can never go negative.
     */
    private function computeNotice(array $data, ?HrExitPolicy $policy, HrExitType $exitType): array
    {
        $requestDate = Carbon::parse($data['request_date'] ?? now()->toDateString())->startOfDay();

        $lastWorking = ! empty($data['last_working_date']) ? Carbon::parse($data['last_working_date'])->startOfDay() : null;
        if ($lastWorking && $lastWorking->lt($requestDate)) {
            throw new BusinessException('Last working date cannot be before the request date.');
        }

        $noticeDays = $this->resolveNoticeDays($data, $policy, $exitType);
        if ($noticeDays < 0) {
            throw new BusinessException('Notice period cannot be negative.');
        }

        $noticeStart = ! empty($data['notice_start_date']) ? Carbon::parse($data['notice_start_date'])->startOfDay() : $requestDate->copy();
        $computedEnd = $noticeStart->copy()->addDays($noticeDays);

        $noticeEnd = $computedEnd;
        if (! empty($data['notice_end_date'])) {
            $requestedEnd = Carbon::parse($data['notice_end_date'])->startOfDay();
            if (! $requestedEnd->equalTo($computedEnd)) {
                $overrideAllowed = ! $policy || (bool) $policy->buyout_allowed;
                if (! $overrideAllowed) {
                    throw new BusinessException('This exit policy does not allow a manual notice-period override.');
                }
                $noticeEnd = $requestedEnd;
            }
        }

        if ($noticeEnd->lt($noticeStart)) {
            throw new BusinessException('Notice period cannot be negative.');
        }

        return [
            'request_date'      => $requestDate->toDateString(),
            'last_working_date' => $lastWorking?->toDateString(),
            'notice_start_date' => $noticeStart->toDateString(),
            'notice_end_date'   => $noticeEnd->toDateString(),
            'notice_days'       => $noticeStart->diffInDays($noticeEnd),
        ];
    }

    private function resolveNoticeDays(array $data, ?HrExitPolicy $policy, HrExitType $exitType): int
    {
        if (array_key_exists('notice_days', $data) && $data['notice_days'] !== null && $data['notice_days'] !== '') {
            return (int) $data['notice_days'];
        }
        if ($policy) {
            return (int) $policy->notice_days;
        }

        return (int) $exitType->default_notice_days;
    }

    private function resolvePolicy(array $data, HrEmployee $employee, int $tenantId): ?HrExitPolicy
    {
        if (! empty($data['exit_policy_id'])) {
            $policy = $this->repo->findPolicy((int) $data['exit_policy_id'], $tenantId);
            if (! $policy) {
                throw new BusinessException('Selected exit policy is invalid.');
            }

            return $policy;
        }

        // Auto-attach from the employee's Organization Setup placement.
        return $this->repo->policyForEmployee($tenantId, $employee->grade_id, $employee->designation_id, $employee->department_id);
    }

    /* ── Presenter + lookups ──────────────────────────────── */

    public function present(HrExitRequest $r, bool $full = false): array
    {
        $out = [
            'id' => $r->id,
            'employee_id' => $r->employee_id,
            'employee_name' => $r->employee?->name,
            'employee_code' => $r->employee?->employee_code,
            'department' => $r->employee?->department,
            'designation' => $r->employee?->designation,
            'exit_type_id' => $r->exit_type_id,
            'exit_type' => $r->exitType?->name,
            'exit_type_code' => $r->exitType?->code,
            'exit_policy_id' => $r->exit_policy_id,
            'policy_name' => $r->policy?->name,
            'request_date' => optional($r->request_date)->toDateString(),
            'last_working_date' => optional($r->last_working_date)->toDateString(),
            'notice_start_date' => optional($r->notice_start_date)->toDateString(),
            'notice_end_date' => optional($r->notice_end_date)->toDateString(),
            'notice_days' => (int) $r->notice_days,
            'reason' => $r->reason,
            'employee_remarks' => $r->employee_remarks,
            'hr_remarks' => $r->hr_remarks,
            'has_attachment' => ! empty($r->attachment_path),
            'status' => $r->status,
            'submitted_at' => optional($r->submitted_at)->toIso8601String(),
            'withdrawn_at' => optional($r->withdrawn_at)->toIso8601String(),
            'created_at' => optional($r->created_at)->toIso8601String(),
            // Approval lifecycle (Phase 3).
            'review_started_at' => optional($r->review_started_at)->toIso8601String(),
            'review_remarks' => $r->review_remarks,
            'decided_at' => optional($r->decided_at)->toIso8601String(),
            'decision_remarks' => $r->decision_remarks,
        ];

        if ($full) {
            $out['timeline'] = $r->relationLoaded('auditLogs')
                ? $r->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrExitRequest
    {
        $request = $this->repo->findRequest($id, $tenantId);
        if (! $request) {
            throw new BusinessException('Exit request not found', 404);
        }

        return $request;
    }

    private function employee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function exitType(int $id, int $tenantId): HrExitType
    {
        $type = HrExitType::where('tenant_id', $tenantId)->find($id);
        if (! $type) {
            throw new BusinessException('Exit type is invalid for this tenant.');
        }

        return $type;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
