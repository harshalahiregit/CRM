<?php

namespace App\Http\Requests\Helpdesk;

use App\Services\Helpdesk\HelpdeskSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Phase 1: validate against the tenant's configured lists, not a hardcoded in:.
        $tenantId = $this->user()->tenant_id;
        $settings = app(HelpdeskSettingsService::class);

        return [
            'subject'       => 'required|string|max:255',
            'description'   => 'nullable|string',
            'status'        => ['nullable', Rule::in($settings->statusNames($tenantId))],
            'priority'      => ['nullable', Rule::in($settings->priorityNames($tenantId))],
            'assigned_to'   => ['nullable', 'integer', TenantRules::assignableUser($tenantId)],
            'customer_id'   => 'nullable|integer|min:1',
            'department_id' => ['nullable', 'integer', TenantRules::department($tenantId)],
            'service_id'    => ['nullable', 'integer', TenantRules::service($tenantId)],
            'due_date'      => 'nullable|date',
            // Requester identity — used for the acknowledgment email + threaded
            // replies when the ticket is raised on someone's behalf.
            'requester_name'  => 'nullable|string|max:255',
            'requester_email' => 'nullable|email|max:255',
            // Standing copy list for the whole ticket, not one message. Every
            // outbound mail on this ticket copies these. `ticket_replies.cc` is
            // still the place for a one-off copy on a single reply.
            'cc'              => 'nullable|array|max:20',
            'cc.*'            => 'email|max:255',
            // Tags are attached after the ticket exists, so the ids are checked
            // here and the linking happens in the service.
            // Files raised WITH the ticket. Same limits as reply attachments.
            'attachments'     => 'nullable|array|max:10',
            'attachments.*'   => 'file|max:10240',
            'tags'            => 'nullable|array|max:20',
            'tags.*'          => ['integer', TenantRules::ticketTag($tenantId)],
            // Link back to the project this ticket was raised from ("Raise Ticket"
            // on a project/task). Tenant ownership is re-checked in the service.
            'project_id'      => 'nullable|integer|min:1',
            // Where the ticket was raised from, so the grid can badge its origin
            // module. Agent-composed tickets omit it (default 'internal'); the
            // "Raise Ticket" button in each module stamps its own value.
            'source'          => ['nullable', 'string', Rule::in([
                'internal', 'project', 'task', 'inventory',
                'hr', 'accounts', 'purchase', 'tpv', 'compliance', 'sales', 'customer',
            ])],
        ];
    }
}
