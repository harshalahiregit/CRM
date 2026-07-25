<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => 'sometimes|required|string|max:255',
            'description'        => 'nullable|string',
            'due_date'           => 'sometimes|required|date',
            'start_date'         => 'nullable|date',
            'color'              => 'nullable|string|max:9',
            'order'              => 'nullable|integer|min:0',
            'hide_from_customer' => 'nullable|boolean',
        ];
    }
}
