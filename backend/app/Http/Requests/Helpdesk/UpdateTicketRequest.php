<?php

namespace App\Http\Requests\Helpdesk;

use App\Services\Helpdesk\HelpdeskSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $settings = app(HelpdeskSettingsService::class);

        return [
            'subject'       => 'sometimes|required|string|max:255',
            'description'   => 'nullable|string',
            'status'        => ['sometimes', Rule::in($settings->statusNames($tenantId))],
            'priority'      => ['sometimes', Rule::in($settings->priorityNames($tenantId))],
            'assigned_to'   => ['nullable', 'integer', TenantRules::assignableUser($tenantId)],
            'customer_id'   => 'nullable|integer|min:1',
            'department_id' => ['nullable', 'integer', TenantRules::department($tenantId)],
            'service_id'    => ['nullable', 'integer', TenantRules::service($tenantId)],
            'due_date'      => 'nullable|date',
        ];
    }
}
