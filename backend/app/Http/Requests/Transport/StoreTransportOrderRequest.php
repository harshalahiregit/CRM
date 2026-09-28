<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\OrderPriority;
use App\Support\Transport\OrderSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SNG-TRN-006 — Transport Order creation.
 *
 * Shape validation only. The gates that need the database — "customer exists,
 * customer active" (STOS-OPS §7) — live in TransportOrderService so they apply
 * however an order arrives, not just through this endpoint.
 *
 * order_number and order_status are deliberately absent: the first is allocated
 * by the numbering engine, the second is always DRAFT on create. Accepting
 * either would let a client choose its own reference or skip the state machine.
 */
class StoreTransportOrderRequest extends FormRequest
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
            // CTR-001 — "exists in tenant… No cross-tenant IDs." A bare
            // exists:clients,id would accept another workspace's customer.
            'customer_id' => [
                'required', 'integer',
                Rule::exists('clients', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'customer_reference' => ['nullable', 'string', 'max:120'],

            // CTR-002 / CTR-003 — OBJECT, address schema. No schema is defined in
            // any approved document, so the shape is validated as far as it is
            // specified and no further: a non-empty object with a line of address.
            'pickup_location'           => ['required', 'array'],
            'pickup_location.address'   => ['required', 'string', 'max:255'],
            'pickup_location.city'      => ['nullable', 'string', 'max:120'],
            'pickup_location.state'     => ['nullable', 'string', 'max:120'],
            'pickup_location.pincode'   => ['nullable', 'string', 'max:20'],
            'pickup_location.contact'   => ['nullable', 'string', 'max:120'],

            'delivery_location'         => ['required', 'array'],
            'delivery_location.address' => ['required', 'string', 'max:255'],
            'delivery_location.city'    => ['nullable', 'string', 'max:120'],
            'delivery_location.state'   => ['nullable', 'string', 'max:120'],
            'delivery_location.pincode' => ['nullable', 'string', 'max:20'],
            'delivery_location.contact' => ['nullable', 'string', 'max:120'],

            'required_at'  => ['required', 'date'],
            'service_type' => ['required', 'string', 'max:80'],

            // OPS §9 / BRW-018 — mandatory, exactly four values.
            'priority' => ['required', Rule::in(OrderPriority::ALL)],
            // OPS §6 — "Every creation must identify source."
            'source'   => ['required', Rule::in(OrderSource::ALL)],

            'rate_reference'       => ['nullable', 'string', 'max:120'],
            'route'                => ['nullable', 'string', 'max:190'],
            'special_requirements' => ['nullable', 'string', 'max:2000'],
            'billing_requirements' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required'             => 'Choose the customer this order is for.',
            'customer_id.exists'               => 'That customer does not exist in this workspace.',
            'pickup_location.address.required' => 'A pickup address is required.',
            'delivery_location.address.required' => 'A delivery address is required.',
            'required_at.required'             => 'Set the date and time the order is required by.',
            'priority.required'                => 'Every order must carry a priority.',
            'source.required'                  => 'Record where this order came from.',
        ];
    }
}
