<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string',
            'priority'          => 'nullable|in:low,medium,high,urgent',
            'status'            => 'nullable|in:not_started,in_progress,awaiting_feedback,testing,complete',
            'start_date'        => 'required|date',
            'due_date'          => 'nullable|date|after_or_equal:start_date',
            'rel_type'          => 'nullable|in:project,ticket,customer,standalone',
            'rel_id'            => 'nullable|integer|min:1|required_unless:rel_type,standalone,null',
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
