<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consignment update.
 *
 * Every field is `sometimes`, so a caller may send one field without resending
 * the rest — a partial update is the ordinary case from a detail page.
 *
 * ── order_id IS ABSENT, AND THAT IS THE POINT ────────────────────────────
 * A consignment cannot be moved to a different order. The link direction is
 * fixed as order → consignment → container, the consignment's number was
 * allocated against that order, and its `customer_id` was denormalised from it
 * (CTD-002). Re-parenting would silently strand the customer on the old order's
 * value.
 *
 * Nothing enforces that here beyond the absence: the service accepts only its
 * WRITABLE list, which does not include order_id, so a client that sends one is
 * ignored rather than refused. A consignment genuinely raised against the wrong
 * order is deleted and re-created, which leaves an audit trail of both acts.
 */
class UpdateConsignmentRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission), not this class's. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cargo_description'  => ['sometimes', 'nullable', 'string', 'max:2000'],
            'service_type'       => ['sometimes', 'nullable', 'string', 'max:60'],
            'special_handling'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'package_count'      => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999'],
            'gross_weight_kg'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'volume_cbm'         => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999.999'],
        ];
    }

    public function messages(): array
    {
        return [
            'package_count.min'   => 'A consignment carries at least one package.',
            'gross_weight_kg.min' => 'Weight cannot be negative.',
            'volume_cbm.min'      => 'Volume cannot be negative.',
        ];
    }
}
