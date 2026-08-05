<?php

namespace App\Http\Requests\Hr;

use App\Rules\Hr\ValidWorkState;
use Illuminate\Foundation\Http\FormRequest;

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
            'department'             => 'required|string',
            'designation'            => 'required|string',
            'reporting_manager_name' => 'nullable|string',
            // Statutory jurisdiction (Professional Tax). Optional — an employee
            // without one simply gets no PT, with the reason recorded on the record.
            'work_state'             => ['nullable', 'string', 'max:80', new ValidWorkState],
            'joining_date'           => 'required|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'in:Active,On Leave,Inactive',
            'skills'                 => 'nullable|array',
            'skills.*'               => 'string|max:60',

            // #29 — captured at entry, which is the comment's "option to consider
            // person in org. chart while entering in system".
            'worker_type'            => 'nullable|in:employee,consultant,freelancer',
            'include_in_org_chart'   => 'nullable|boolean',

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
