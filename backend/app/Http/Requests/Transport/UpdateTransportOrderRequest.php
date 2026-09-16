<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\OrderPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amend a DRAFT order. The service refuses anything past draft.
 *
 * order_number, order_status and source are not accepted: the reference is
 * allocated once, status moves only through the transition endpoint, and source
 * records how the order arrived — rewriting it would falsify its provenance.
 */
class UpdateTransportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'customer_id' => [
                'sometimes', 'integer',
                Rule::exists('clients', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'customer_reference' => ['sometimes', 'nullable', 'string', 'max:120'],

            'pickup_location'           => ['sometimes', 'array'],
            'pickup_location.address'   => ['required_with:pickup_location', 'string', 'max:255'],
            'delivery_location'         => ['sometimes', 'array'],
            'delivery_location.address' => ['required_with:delivery_location', 'string', 'max:255'],

            'required_at'  => ['sometimes', 'date'],
            'service_type' => ['sometimes', 'string', 'max:80'],
            'priority'     => ['sometimes', Rule::in(OrderPriority::ALL)],

            'rate_reference'       => ['sometimes', 'nullable', 'string', 'max:120'],
            'route'                => ['sometimes', 'nullable', 'string', 'max:190'],
            'special_requirements' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'billing_requirements' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
