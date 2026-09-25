<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'project_id'          => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'location'            => 'nullable|string|max:150',
            'employee_level'      => 'nullable|string|max:60',
            'experience_required' => 'nullable|string|max:100',
            // Kept identical to StoreManpowerRequest — see the reasoning there.
            // An edit that refused what the create accepted would be worse than
            // either limit on its own: the record saves once and can never be
            // touched again.
            'education'           => 'nullable|string|max:255',
            'criticality'         => 'nullable|in:Low,Medium,High,Business Critical',
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string|max:2000',
            'preferred_skills'    => 'nullable|array',
            'preferred_skills.*'  => 'string|max:2000',
            'job_description'     => 'nullable|string',
            'justification'       => 'nullable|string',
            'required_by_date'    => 'nullable|date|after_or_equal:today',
            'target_joining_date' => 'nullable|date',
            // Enterprise fields (SPK-1) — all optional, backward compatible.
            'hiring_manager_id'      => 'nullable|exists:hr_employees,id',
            'work_mode'              => 'nullable|in:Onsite,Remote,Hybrid',
            // As the store rule, plus whatever this requisition already holds.
            //
            // Existing rows carry the legacy names, and a workspace that has
            // since configured its own shifts would otherwise be unable to save
            // ANY edit to an old requisition — the untouched shift field would
            // fail validation and take the whole update down with it. The rule
            // applies to a shift being changed, not to one being carried.
            'shift'                  => ['nullable', Rule::in($this->allowedShifts())],
            'budget'                 => 'nullable|numeric|min:0',
            'certifications'         => 'nullable|array',
            'certifications.*'       => 'string|max:2000',
            'hiring_reason'          => 'nullable|in:New Position,Replacement,Expansion,Contract',
            'replacement_employee_id' => 'nullable|required_if:hiring_reason,Replacement|exists:hr_employees,id',
            'cost_center'            => 'nullable|string|max:100',
        ];
    }

    /**
     * The shift names this particular update may save.
     *
     * The workspace's configured shifts, plus the value already stored on the
     * requisition being edited so an existing row stays editable. Nothing is
     * backfilled and nothing already saved is invalidated — the old value
     * remains valid for the row that holds it, and for no other.
     *
     * @return array<int, string>
     */
    private function allowedShifts(): array
    {
        $allowed = \App\Services\Hr\OrganizationService::shiftOptions((int) $this->user()->tenant_id);

        $current = $this->route('manpowerRequest');
        $stored  = is_object($current) ? $current->shift : null;

        if ($stored !== null && $stored !== '' && ! in_array($stored, $allowed, true)) {
            $allowed[] = $stored;
        }

        return $allowed;
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
