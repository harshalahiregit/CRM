<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Editable job fields. Status is changed via the lifecycle endpoints. */
    public function rules(): array
    {
        return [
            'title'              => 'sometimes|string|max:200',
            'department'         => 'sometimes|string|max:100',
            'location'           => 'sometimes|string|max:100',
            'job_type'           => 'sometimes|in:Full-time,Part-time,Contract,Internship,Remote',
            'posting_type'       => 'sometimes|in:Internal,External,Both',
            'work_mode'          => 'nullable|in:Onsite,Remote,Hybrid',
            'campaign_number'    => 'nullable|string|max:100',
            'description'        => 'nullable|string',
            'requirements'       => 'nullable|string',
            'salary_from'        => 'nullable|numeric|min:0',
            'salary_to'          => 'nullable|numeric|min:0',
            'number_of_openings' => 'sometimes|integer|min:1',
            'closing_date'       => 'nullable|date',
            'sources'            => 'nullable|array',
            'screening_questions'          => 'nullable|array',
            'screening_questions.*.id'     => 'nullable|string',
            'screening_questions.*.label'  => 'required_with:screening_questions|string|max:500',
            'screening_questions.*.type'   => 'required_with:screening_questions|in:short_text,long_text,yes_no,dropdown,radio,checkbox',
            'screening_questions.*.mandatory' => 'nullable|boolean',
            'screening_questions.*.order'  => 'nullable|integer',
            'screening_questions.*.options' => 'nullable|array',
        ];
    }
}
