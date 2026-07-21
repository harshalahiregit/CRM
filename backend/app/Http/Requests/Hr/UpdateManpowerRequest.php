<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department'          => 'sometimes|string|max:100',
            'position_title'      => 'sometimes|string|max:200',
            'number_of_posts'     => 'sometimes|integer|min:1|max:1000',
            'priority'            => 'sometimes|in:Low,Medium,High,Critical',
            'job_type'            => 'sometimes|in:Full-time,Part-time,Contract,Internship',
            'business_unit'       => 'nullable|string|max:150',
            'project'             => 'nullable|string|max:150',
            'location'            => 'nullable|string|max:150',
            'employee_level'      => 'nullable|string|max:60',
            'experience_required' => 'nullable|string|max:100',
            'education'           => 'nullable|string|max:150',
            'criticality'         => 'nullable|in:Low,Medium,High,Business Critical',
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string|max:60',
            'preferred_skills'    => 'nullable|array',
            'preferred_skills.*'  => 'string|max:60',
            'job_description'     => 'nullable|string',
            'justification'       => 'nullable|string',
            'required_by_date'    => 'nullable|date|after_or_equal:today',
            'target_joining_date' => 'nullable|date',
            // Enterprise fields (SPK-1) — all optional, backward compatible.
            'hiring_manager_id'      => 'nullable|exists:hr_employees,id',
            'work_mode'              => 'nullable|in:Onsite,Remote,Hybrid',
            'shift'                  => 'nullable|in:Day,Night,Rotational,Flexible',
            'budget'                 => 'nullable|numeric|min:0',
            'certifications'         => 'nullable|array',
            'certifications.*'       => 'string|max:100',
            'hiring_reason'          => 'nullable|in:New Position,Replacement,Expansion,Contract',
            'replacement_employee_id' => 'nullable|required_if:hiring_reason,Replacement|exists:hr_employees,id',
            'cost_center'            => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'required_by_date.after_or_equal'  => 'Required By date cannot be earlier than today.',
            'salary_max.gte'                   => 'Salary Max cannot be less than Salary Min.',
            'replacement_employee_id.required_if' => 'Select the employee being replaced.',
        ];
    }
}
