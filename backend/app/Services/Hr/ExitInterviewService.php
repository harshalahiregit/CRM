<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExitInterview;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Exit Interview (SPK-1) — internal replacement for the reference Google Form.
 *
 * Everything the employee record already holds is auto-filled and never asked
 * for again; only the answers the organisation does not already have are
 * collected. Reuses hr_employees (and, through it, the candidate/offer chain).
 */
class ExitInterviewService
{
    public function list(int $tenantId): Collection
    {
        return HrExitInterview::where('tenant_id', $tenantId)
            ->with('employee:id,employee_code,name,department,designation,reporting_manager_name,joining_date,status')
            ->latest()
            ->get();
    }

    /**
     * Everything the form asks that we already know. The UI renders these
     * read-only, so the employee only fills what is genuinely new.
     */
    public function prefill(HrEmployee $employee, ?string $companyName = null): array
    {
        return [
            'employee_id'       => $employee->id,
            'full_name'         => $employee->name,
            'employee_code'     => $employee->employee_code,
            'department'        => $employee->department,
            'designation'       => $employee->designation,
            'reporting_manager' => $employee->reporting_manager_name,
            'joining_date'      => optional($employee->joining_date)->toDateString(),
            'work_email'        => $employee->email,
            'work_phone'        => $employee->phone,
            'employment_status' => $employee->status,
            // Prefilled but editable — the employee may want to name their project.
            'organization_or_project' => $companyName ?: $employee->department,
        ];
    }

    public function save(HrEmployee $employee, array $data, ?User $actor = null, bool $submit = false): HrExitInterview
    {
        // One exit interview per employee — reopen the existing draft rather than
        // creating a second record for the same exit.
        $exit = HrExitInterview::firstOrNew([
            'tenant_id'   => $employee->tenant_id,
            'employee_id' => $employee->id,
        ]);

        if ($exit->exists && $exit->status === 'Submitted') {
            throw new BusinessException('This exit interview has already been submitted.', 422);
        }

        $exit->fill($data);
        $exit->tenant_id   = $employee->tenant_id;
        $exit->employee_id = $employee->id;

        if ($submit) {
            $exit->status = 'Submitted';
            $exit->submitted_at = now();
        }
        $exit->save();

        $action = $submit ? 'Exit Interview Submitted' : 'Exit Interview Saved';
        $employee->recordAudit($action, $actor, null, array_filter([
            'exit_interview_id' => $exit->id,
            'exit_date'         => optional($exit->exit_date)->toDateString(),
            'rating'            => $exit->rating,
        ]));

        Log::channel('hr')->info('Exit interview '.($submit ? 'submitted' : 'saved'), [
            'employee_id' => $employee->id, 'tenant_id' => $employee->tenant_id, 'exit_interview_id' => $exit->id,
        ]);

        return $exit->fresh('employee');
    }
}
