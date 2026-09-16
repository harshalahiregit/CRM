<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attaching a container to a consignment — `STOS-CTD §7`, `§8`.
 *
 * The one-active-attachment rule is NOT checked here. It cannot be: two
 * concurrent requests would both pass a validation rule and both insert.
 * ContainerService checks it so the caller gets a sentence rather than a
 * constraint violation, and the unique index over `active_container_key` is
 * what actually guarantees it. See ContainerService's docblock and D-53.
 */
class AttachContainerRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission), not this class's. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            // Tenant-scoped: a bare exists:transport_consignments,id would
            // accept another workspace's consignment and confirm its existence.
            // The service refuses it again with a 404 — this rule exists so a
            // form can highlight the right input instead.
            'consignment_id' => [
                'required', 'integer',
                Rule::exists('transport_consignments', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'consignment_id.required' => 'Choose the consignment to put this container on.',
            'consignment_id.exists'   => 'That consignment does not exist in this workspace.',
        ];
    }
}
