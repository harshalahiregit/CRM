<?php

namespace App\Http\Requests\Hr;

use App\Rules\Hr\ValidWorkState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => 'required|string',
            'email'                  => 'nullable|email',
            'phone'                  => 'nullable|string',
            'dob'                    => 'nullable|date',
            'gender'                 => 'nullable|in:Male,Female,Other,Prefer not to say',
            'address'                => 'nullable|string',
            // The MASTER RECORD, not a typed string.
            //
            // hr_employees has carried department_id and designation_id since
            // the organization tables shipped, and this form wrote neither —
            // it stored whatever text arrived, so the same department existed
            // under several spellings and the org chart, which reads the FK,
            // saw almost nobody. The name is no longer accepted here at all:
            // EmployeeService copies it from the master it resolves, so there
            // is one editable source instead of two that drifted.
            //
            // Scoped to the caller's tenant the same way reporting_manager_id
            // below is — a bare exists: rule admits another workspace's
            // department and turns an authorisation problem into a 422.
            'department_id'          => [
                'required', 'integer',
                Rule::exists('hr_departments', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'designation_id'         => [
                'required', 'integer',
                Rule::exists('hr_designations', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            // Two fields, on purpose, and they are not duplicates of each other.
            //
            // reporting_manager_id is the IDENTITY, and it is what every feature
            // that walks the hierarchy reads: the org chart, the advance ladder's
            // manager rung, the attendance app's approval queue. Until now it had
            // exactly one writer in the whole codebase — EmployeeMovementService,
            // the transfer flow — so a person's manager only became real if they
            // were later moved. Hired and left alone, they had none.
            //
            // reporting_manager_name stays because it is the only thing that can
            // hold a manager who is not an employee record at all ("CEO" in the
            // seeded data), and because three screens render it. Id where we have
            // one, name where we do not; neither is derived from the other.
            'reporting_manager_id'   => [
                'nullable', 'integer',
                Rule::exists('hr_employees', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'reporting_manager_name' => 'nullable|string',
            // Statutory jurisdiction (Professional Tax). Optional — an employee
            // without one simply gets no PT, with the reason recorded on the record.
            'work_state'             => ['nullable', 'string', 'max:80', new ValidWorkState],
            // A standing notice period for this person. Absent or null means
            // "inherit" — the exit policy matched to their grade, then the exit
            // type's default. 0 is a real value meaning no notice is served,
            // so `nullable` rather than a falsy check is what keeps them apart.
            'notice_days'            => 'nullable|integer|min:0|max:365',
            'joining_date'           => 'required|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'in:Active,On Leave,Inactive',
            'skills'                 => 'nullable|array',
            'skills.*'               => 'string|max:60',

            // #29 — captured at entry, which is the comment's "option to consider
            // person in org. chart while entering in system".
            'worker_type'            => 'nullable|in:employee,consultant,freelancer',
            'include_in_org_chart'   => 'nullable|boolean',
            // Granted by HR, never assumed. Defaults to off at the column.
            'app_login_enabled'      => 'nullable|boolean',

            // #36 — probation must be set when adding an employee. A policy is
            // required unless the hire is explicitly exempted, and an exemption
            // must carry a reason so it is never a silent omission.
            'skip_probation'         => 'nullable|boolean',
            'probation_skip_reason'  => 'required_if:skip_probation,true,1|nullable|string|max:500',
            'probation_policy_id'    => 'required_unless:skip_probation,true,1|nullable|integer',
            'probation_start_date'   => 'nullable|date',
            'probation_end_date'     => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'probation_policy_id.required_unless' => 'Choose a probation policy, or mark this hire as exempt with a reason.',
            'probation_skip_reason.required_if'   => 'Give a reason for skipping probation — an exemption must be explainable.',
        ];
    }
}
