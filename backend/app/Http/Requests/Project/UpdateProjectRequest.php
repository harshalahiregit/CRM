<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => 'sometimes|required|string|max:255',
            'description'         => 'nullable|string',
            'status'              => 'sometimes|in:not_started,in_progress,on_hold,finished,cancelled',
            'customer_id'         => 'nullable|integer|min:1',
            'billing_type'        => 'sometimes|in:fixed,project_hours,task_hours',
            'project_cost'        => 'nullable|numeric|min:0',
            'rate_per_hour'       => 'nullable|numeric|min:0',
            'start_date'          => 'sometimes|required|date',
            'deadline'            => 'nullable|date',
            'progress'            => 'nullable|integer|min:0|max:100',
            'progress_from_tasks' => 'nullable|boolean',
            'estimated_hours'     => 'nullable|numeric|min:0',
        ];
    }
}
