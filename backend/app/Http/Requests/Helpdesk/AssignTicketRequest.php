<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // present + nullable: the key must be sent, but null means "unassign".
        // TicketAssignmentService already re-checks tenancy, but validating here
        // turns a 422-after-work into a clean 422 and keeps this route honest on
        // its own terms rather than relying on the service as the only guard.
        return [
            'assigned_to' => ['present', 'nullable', 'integer', TenantRules::assignableUser($this->user()->tenant_id)],
        ];
    }
}
