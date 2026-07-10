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
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string|max:60',
            'job_description'     => 'nullable|string',
            'justification'       => 'nullable|string',
            'required_by_date'    => 'nullable|date',
            'target_joining_date' => 'nullable|date',
        ];
    }
}
