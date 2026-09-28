<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Core
            'department'          => 'required|string|max:100',
            'position_title'      => 'required|string|max:200',
            'number_of_posts'     => 'required|integer|min:1|max:1000',
            'priority'            => 'required|in:Low,Medium,High,Critical',
            'job_type'            => 'required|in:Full-time,Part-time,Contract,Internship',
            // Extended hiring information
            'business_unit'       => 'nullable|string|max:150',
            'project'             => 'nullable|string|max:150',
            // Project integration (existing Projects module). Tenant-scoped; NOT gated to
            // active status, so an inactive/archived project on an existing record still saves.
            'project_id'          => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'location'            => 'nullable|string|max:150',
            'employee_level'      => 'nullable|string|max:60',
            'experience_required' => 'nullable|string|max:100',
            // 255 because that is what the column holds (string() with no length).
            // Was 150, which refused a qualification written out in full.
            'education'           => 'nullable|string|max:255',
            'criticality'         => 'nullable|in:Low,Medium,High,Business Critical',
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            // A skill here is not a keyword. Hiring managers write a requirement
            // out in prose — "5+ years building distributed systems, ideally with
            // Kafka" — and 60 characters refused that at the 61st, with an alert
            // that said only "Validation failed" and named no field. Nobody could
            // see what to shorten.
            //
            // NO LENGTH LIMIT, by decision. It was 60, then 2000, and a cap is
            // still somebody guessing how long a requirement ought to be. These
            // three are TEXT columns holding the whole array as json, so the
            // database was never the constraint.
            //
            // What remains is not a validation limit and cannot be removed here:
            // MySQL's max_allowed_packet bounds the whole request body. Past it
            // the request fails at the connection rather than as a clean 422.
            // That ceiling is megabytes and the form caps the list at 30 entries,
            // so it is far outside anything a person types — but it is the reason
            // "unlimited" is a statement about intent rather than about physics.
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string',
            'preferred_skills'    => 'nullable|array',
            'preferred_skills.*'  => 'string',
            'job_description'     => 'nullable|string',
            'justification'       => 'nullable|string',
            // Business rule (SPK-1): a request cannot be needed in the past.
            'required_by_date'    => 'nullable|date|after_or_equal:today',
            'target_joining_date' => 'nullable|date',
            'assigned_manager_id' => 'nullable|exists:users,id',
            // Enterprise fields (SPK-1) — all optional, backward compatible.
            'hiring_manager_id'      => 'nullable|exists:hr_employees,id',
            'work_mode'              => 'nullable|in:Onsite,Remote,Hybrid',
            // Whatever this workspace configured under HR Settings, or the
            // legacy names when it has configured none. Resolved from the same
            // place the dropdown reads, so the field cannot offer a value the
            // validator then refuses.
            'shift'                  => ['nullable', Rule::in(
                \App\Services\Hr\OrganizationService::shiftOptions((int) $this->user()->tenant_id)
            )],
            'budget'                 => 'nullable|numeric|min:0',
            // Same reasoning as the skills above — a json column, and a
            // certification is often named in full with its issuing body.
            'certifications'         => 'nullable|array',
            'certifications.*'       => 'string',
            'hiring_reason'          => 'nullable|in:New Position,Replacement,Expansion,Contract',
            // Replacement employee only makes sense for a replacement hire.
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
