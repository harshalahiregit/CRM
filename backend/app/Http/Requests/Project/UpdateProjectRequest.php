<?php

namespace App\Http\Requests\Project;

use App\Services\StatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Statuses are tenant-configurable (Advanced Status Manager), so the
        // allowed values come from the lookup table, not a literal list.
        $statusKeys = app(StatusService::class)->keys('project', $this->user()->tenant_id);

        return [
            'name'                => 'sometimes|required|string|max:255',
            'description'         => 'nullable|string',
            'status'              => ['sometimes', Rule::in($statusKeys)],
            'customer_id'         => 'nullable|integer|min:1',
            'vendor_user_id'      => 'nullable|integer|min:1',
            'link_type'           => 'nullable|in:customer,vendor,tpv,tpv_vendor,purchase_vendor',
            // vendor_id is the vendor RECORD id (vendors / purchase_vendors),
            // used by the tpv_vendor / purchase_vendor link types.
            'vendor_id'           => 'nullable|integer|min:1',
            'billing_type'        => 'sometimes|in:fixed,project_hours,task_hours',
            'project_cost'        => 'nullable|numeric|min:0',
            'rate_per_hour'       => 'nullable|numeric|min:0',
            'start_date'          => 'sometimes|required|date',
            'deadline'            => 'nullable|date',
            'progress'            => 'nullable|integer|min:0|max:100',
            'progress_from_tasks' => 'nullable|boolean',
            'estimated_hours'     => 'nullable|numeric|min:0',
            // Members and tags are child rows, not columns — the service splits them out.
            'member_ids'          => 'nullable|array|max:50',
            'member_ids.*'        => 'integer|exists:users,id',
            'tags'                => 'nullable|array|max:15',
            'tags.*'              => 'string|max:60',
            // Bags of booleans, read and written whole (see the migration). Unknown
            // keys are dropped by the service against config/projects.php.
            'visible_tabs'          => 'nullable|array',
            'customer_permissions'  => 'nullable|array',
            'vendor_permissions'    => 'nullable|array',
            'tpv_permissions'       => 'nullable|array',
            // Project Settings (Tab 2) + the Tab-1 "send created email" checkbox.
            'hide_tasks_on_main'    => 'nullable|boolean',
            'send_created_email'    => 'nullable|boolean',
            'contacts_notification' => 'nullable|in:all,specific,none',
        ];
    }
}
