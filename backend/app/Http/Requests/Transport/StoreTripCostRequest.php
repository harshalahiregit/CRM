<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\CostSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording what a trip cost — SNG-TRN-012, DB-006.
 *
 * `cost_type` is validated for SHAPE only, never against a list. FLD-010's
 * vocabulary reference CST-001 is defined nowhere in Step 11 (D-58), so a rule
 * that rejected an unlisted type would be enforcing a vocabulary this developer
 * invented — FORBID-001. Length is checked because the column is VARCHAR(40)
 * and a silently truncated type becomes its own row in the margin breakdown.
 *
 * `source` IS constrained, and the asymmetry is deliberate — see CostSource for
 * why one may be closed and the other may not. The service narrows this further
 * still: a human request may only claim an operator-writable source, so posting
 * `telemetry` is refused there rather than here, where the actor is not yet in
 * view.
 *
 * `amount` uses `decimal:0,2` rather than plain `numeric`, so 1200.999 is
 * refused instead of being quietly rounded into money that will later be
 * subtracted from revenue.
 *
 * `confirm_duplicate` is not permission to duplicate — it is an acknowledgement
 * that the look-alike the service reported was seen. It only ever applies to
 * hand-keyed rows; system sources are deduplicated by the unique index and
 * ignore it entirely.
 */
class StoreTripCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cost_type'         => ['required', 'string', 'max:40'],
            'amount'            => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'currency'          => ['nullable', 'string', 'size:3'],
            'source'            => ['nullable', 'string', Rule::in(CostSource::ALL)],
            'source_ref'        => ['nullable', 'string', 'max:100'],
            // A cost cannot be incurred after today; a future-dated one is a
            // typo, and "boundary dates" is a listed edge case for this ticket.
            'incurred_on'       => ['nullable', 'date', 'before_or_equal:today'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'cost_type.required'    => 'A cost type is required.',
            'cost_type.max'         => 'A cost type may be at most 40 characters.',
            'amount.gt'             => 'A cost amount must be greater than zero.',
            'amount.decimal'        => 'An amount may have at most two decimal places.',
            'source.in'             => 'Unknown cost source.',
            'incurred_on.before_or_equal' => 'A cost cannot be dated in the future.',
        ];
    }
}
