<?php

namespace App\Http\Requests\Task;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        // Statuses are tenant-configurable (Advanced Status Manager).
        $statusKeys = app(StatusService::class)->keys('task', $tenantId);

        return [
            'name'              => 'sometimes|required|string|max:255',
            'description'       => 'nullable|string',
            'priority'          => 'sometimes|in:low,medium,high,urgent',
            'status'            => ['sometimes', Rule::in($statusKeys)],
            'start_date'        => 'sometimes|required|date',
            'due_date'          => 'nullable|date',
            'rel_type'          => 'sometimes|in:project,ticket,customer,contract,tpv_vendor,purchase_vendor,standalone',
            'rel_id'            => 'nullable|integer|min:1|required_if:rel_type,project,ticket,customer,contract,tpv_vendor,purchase_vendor',
            // Tenant-scoped: a bare exists: lets one workspace attach to another's milestone.
            'milestone_id'      => ['nullable', 'integer', Rule::exists('project_milestones', 'id')->where('tenant_id', $tenantId)],
            'billable'          => 'nullable|boolean',
            'billed'            => 'nullable|boolean',
            'hourly_rate'       => 'nullable|numeric|min:0',
            'is_public'         => 'nullable|boolean',
            'visible_to_client' => 'nullable|boolean',
            'kanban_order'      => 'nullable|integer|min:0',
            'recurring'         => 'nullable|boolean',
            'recurring_type'    => 'nullable|required_if:recurring,true|in:day,week,month,year',
            'repeat_every'      => 'nullable|required_if:recurring,true|integer|min:1|max:365',
            'cycles'            => 'nullable|integer|min:0|max:1000',
            'tags'              => 'nullable|array|max:15',
            'tags.*'            => 'string|max:60',
        ];
    }
}
