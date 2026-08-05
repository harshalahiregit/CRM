<?php

namespace App\Http\Requests\Task;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Quick-create paths from other modules (e.g. Sales "Add task" on a contract,
     * Customer "convert note to task") send just a name + rel link and no dates.
     * Default start_date to today so those integrations work, matching how
     * StoreSubtaskRequest already treats an omitted start_date. An explicit value
     * is still validated by the rule below.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('start_date')) {
            $this->merge(['start_date' => now()->toDateString()]);
        }
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        // Statuses are tenant-configurable (Advanced Status Manager), so the
        // allowed values come from the lookup table, not a literal list.
        $statusKeys = app(StatusService::class)->keys('task', $tenantId);
        // The doc asks for milestone to be mandatory *while creating* a project
        // task — but only when the workspace has switched that on, so standalone
        // and non-project workspaces are unaffected.
        $requireMilestone = app(\App\Services\Task\TaskConfigService::class)->on($tenantId, 'require_milestone');

        return [
            'name'              => 'required|string|max:255',
            'description'       => 'nullable|string',
            'priority'          => 'nullable|in:low,medium,high,urgent',
            'status'            => ['nullable', Rule::in($statusKeys)],
            'start_date'        => 'required|date',
            'due_date'          => 'nullable|date|after_or_equal:start_date',
            'rel_type'          => 'nullable|in:project,ticket,customer,contract,tpv_vendor,purchase_vendor,standalone',
            // Only linked types need an id. An omitted rel_type means standalone,
            // which required_unless treated as "no match" and so demanded a rel_id.
            'rel_id'            => 'nullable|integer|min:1|required_if:rel_type,project,ticket,customer,contract,tpv_vendor,purchase_vendor',
            // Tenant-scoped: a bare exists: lets one workspace attach to another's milestone.
            'milestone_id'      => [
                $requireMilestone ? 'required_if:rel_type,project' : 'nullable',
                'integer',
                Rule::exists('project_milestones', 'id')->where('tenant_id', $tenantId),
            ],
            'billable'          => 'nullable|boolean',
            'billed'            => 'nullable|boolean',
            'hourly_rate'       => 'nullable|numeric|min:0',
            'rate_unit'         => 'nullable|in:hourly,daily,monthly,fixed',
            'is_public'         => 'nullable|boolean',
            'visible_to_client' => 'nullable|boolean',
            'kanban_order'      => 'nullable|integer|min:0',
            // Assigning at creation notifies the assignee immediately (see TaskService::create).
            'assignee_ids'      => 'nullable|array|max:20',
            'assignee_ids.*'    => 'integer|exists:users,id',
            'follower_ids'      => 'nullable|array|max:20',
            'follower_ids.*'    => 'integer|exists:users,id',
            // Recurrence: a recurring task is a template the scheduler copies.
            'recurring'         => 'nullable|boolean',
            'recurring_type'    => 'nullable|required_if:recurring,true|in:day,week,month,year',
            'repeat_every'      => 'nullable|required_if:recurring,true|integer|min:1|max:365',
            'cycles'            => 'nullable|integer|min:0|max:1000',   // 0 = forever
            // Shared tags — created on demand by name (see TagService).
            'tags'              => 'nullable|array|max:15',
            'tags.*'            => 'string|max:60',
        ];
    }
}
