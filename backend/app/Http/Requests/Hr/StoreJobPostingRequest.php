<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'              => 'required|string|max:200',
            'department'         => 'required|string|max:100',
            'location'           => 'required|string|max:100',
            'job_type'           => 'required|in:Full-time,Part-time,Contract,Internship,Remote',
            'posting_type'       => 'required|in:Internal,External,Both',
            'work_mode'          => 'nullable|in:Onsite,Remote,Hybrid',
            'campaign_number'    => 'nullable|string|max:100',
            'manpower_request_id' => 'nullable|integer',
            'description'        => 'nullable|string',
            'requirements'       => 'nullable|string',
            'salary_from'        => 'nullable|numeric',
            'salary_to'          => 'nullable|numeric',
            'number_of_openings' => 'required|integer|min:1',
            'closing_date'       => 'nullable|date',
            'status'             => 'in:Active,Draft,Closed',
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
