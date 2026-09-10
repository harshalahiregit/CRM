<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SNG-TRN-007 — create a Trip from an approved Order.
 *
 * CTR-004: order_id is required and must belong to the tenant — "Cannot create
 * orphan trip". That the order is APPROVED, and that it has no other live trip,
 * are business rules and live in the service.
 *
 * trip_number and status are not accepted: the reference is allocated by the
 * numbering engine and is immutable, and a trip always starts at DRAFT.
 * vehicle_id / driver_id are not accepted either — allocation is SNG-TRN-009.
 */
class StoreTransportTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'order_id' => [
                'required', 'integer',
                Rule::exists('transport_orders', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'approved_freight' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'route'            => ['nullable', 'string', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'order_id.required' => 'A trip must be created from an order.',
            'order_id.exists'   => 'That order does not exist in this workspace.',
        ];
    }
}
