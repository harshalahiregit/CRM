<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for an advance against a trip — TRP-P0-007, DB-007.
 *
 * `status` is absent, and so is `amount_approved`. The caller states what it
 * wants; what it gets is the service's decision. Accepting either from the
 * request would let a client approve its own advance by posting the field.
 *
 * `override_limit` is accepted but is not permission to override — it is a
 * REQUEST to. TripAdvanceService still checks that the workspace permits
 * overrides at all and that somebody identifiable is asking, so the flag on its
 * own lifts nothing.
 *
 * Amounts are validated as numeric here and re-normalised to two decimals in
 * the service. `decimal:0,2` rather than `numeric` alone, because a request for
 * 5000.999 should be refused rather than silently rounded into money.
 */
class StoreTripAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_requested' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
            // Exactly one of these — the service enforces it, because "one or
            // the other but not both" is awkward to express here and the rule
            // must hold however the advance is created.
            'driver_id'        => ['nullable', 'integer', 'min:1'],
            'supplier_id'      => ['nullable', 'integer', 'min:1'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'purpose'          => ['nullable', 'string', 'max:500'],
            'payment_method'   => ['nullable', 'string', 'max:40'],
            'override_limit'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_requested.gt'      => 'An advance must be greater than zero.',
            'amount_requested.decimal' => 'An advance may have at most two decimal places.',
        ];
    }
}
