<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'status'              => 'nullable|in:not_started,in_progress,on_hold,finished,cancelled',
            'customer_id'         => 'nullable|integer|min:1',
            'billing_type'        => 'nullable|in:fixed,project_hours,task_hours',
            'project_cost'        => 'nullable|numeric|min:0',
            'rate_per_hour'       => 'nullable|numeric|min:0',
            'start_date'          => 'required|date',
            'deadline'            => 'nullable|date|after_or_equal:start_date',
            'progress'            => 'nullable|integer|min:0|max:100',
            'progress_from_tasks' => 'nullable|boolean',
            'estimated_hours'     => 'nullable|numeric|min:0',
        ];
    }
}
