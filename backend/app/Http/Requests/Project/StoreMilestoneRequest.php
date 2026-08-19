<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => 'required|string|max:255',
            'description'        => 'nullable|string',
            // A milestone cannot end before it begins — the due date must be on or
            // after the start date whenever both are supplied.
            'due_date'           => 'required|date|after_or_equal:start_date',
            'start_date'         => 'nullable|date',
            'color'              => 'nullable|string|max:9',
            'order'              => 'nullable|integer|min:0',
            'hide_from_customer' => 'nullable|boolean',
            'show_description_to_customer' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => 'Due date cannot be before the start date.',
        ];
    }
}
