<?php

namespace App\Http\Requests\Transport;

/**
 * PATCH /transport/trips/{trip}/dispatch/amend — FRS TRP-P0-006.
 *
 * "Freeze key dispatch fields after release; changes create version."
 *
 * Same five fields as the release, plus a MANDATORY reason. The reason is the
 * whole difference between the two endpoints: before release a dispatch field
 * is ordinary data, after release changing it is an act that has to explain
 * itself. TRP-P0-006 also wants "Change approval after release"; no approval
 * entity exists in Step 11 and every other approval in this package is P1, so
 * the change is versioned and audited but not gated on an approver. Recorded in
 * DispatchScope::EXCLUDED.
 */
class AmendDispatchRequest extends DispatchTripRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'reason.required' => 'A dispatch field is frozen once the trip is released. Give a reason for the change.',
        ]);
    }
}
