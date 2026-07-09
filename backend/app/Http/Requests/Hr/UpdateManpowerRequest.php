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
            'department'        => 'sometimes|string|max:100',
            'position_title'    => 'sometimes|string|max:200',
            'number_of_posts'   => 'sometimes|integer|min:1',
            'priority'          => 'sometimes|in:Low,Medium,High',
            'job_type'          => 'sometimes|in:Full-time,Part-time,Contract,Internship',
            'required_by_date'  => 'nullable|date',
            'justification'     => 'nullable|string',
        ];
    }
}
