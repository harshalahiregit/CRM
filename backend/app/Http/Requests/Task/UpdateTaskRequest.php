<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => 'sometimes|required|string|max:255',
            'description'       => 'nullable|string',
            'priority'          => 'sometimes|in:low,medium,high,urgent',
            'status'            => 'sometimes|in:not_started,in_progress,awaiting_feedback,testing,complete',
            'start_date'        => 'sometimes|required|date',
            'due_date'          => 'nullable|date',
            'milestone_id'      => 'nullable|integer|exists:project_milestones,id',
            'billable'          => 'nullable|boolean',
            'billed'            => 'nullable|boolean',
            'hourly_rate'       => 'nullable|numeric|min:0',
            'is_public'         => 'nullable|boolean',
            'visible_to_client' => 'nullable|boolean',
            'kanban_order'      => 'nullable|integer|min:0',
        ];
    }
}
