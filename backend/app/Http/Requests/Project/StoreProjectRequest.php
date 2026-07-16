<?php

namespace App\Http\Requests\Project;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Statuses are tenant-configurable (Advanced Status Manager).
        $statusKeys = app(StatusService::class)->keys('project', $this->user()->tenant_id);

        return [
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'status'              => ['nullable', Rule::in($statusKeys)],
            'customer_id'         => 'nullable|integer|min:1',
            'billing_type'        => 'nullable|in:fixed,project_hours,task_hours',
            'project_cost'        => 'nullable|numeric|min:0',
            'rate_per_hour'       => 'nullable|numeric|min:0',
            'start_date'          => 'required|date',
            'deadline'            => 'nullable|date|after_or_equal:start_date',
            'progress'            => 'nullable|integer|min:0|max:100',
            'progress_from_tasks' => 'nullable|boolean',
            'estimated_hours'     => 'nullable|numeric|min:0',
            // Members and tags are child rows, not columns — the service splits them out.
            'member_ids'          => 'nullable|array|max:50',
            'member_ids.*'        => 'integer|exists:users,id',
            'tags'                => 'nullable|array|max:15',
            'tags.*'              => 'string|max:60',
            // Bags of booleans, read and written whole (see the migration).
            'visible_tabs'          => 'nullable|array',
            'customer_permissions'  => 'nullable|array',
        ];
    }
}
