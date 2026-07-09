<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department'          => 'required|string|max:100',
            'position_title'      => 'required|string|max:200',
            'number_of_posts'     => 'required|integer|min:1',
            'priority'            => 'required|in:Low,Medium,High',
            'job_type'            => 'required|in:Full-time,Part-time,Contract,Internship',
            'required_by_date'    => 'nullable|date',
            'justification'       => 'nullable|string',
            'assigned_manager_id' => 'nullable|exists:users,id',
        ];
    }
}
