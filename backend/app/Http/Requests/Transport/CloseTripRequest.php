<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /transport/trips/{trip}/close — API-009.
 *
 * ── CTR-013, QUOTED ───────────────────────────────────────────────────────
 *   closure_reason | body | TEXT | Required 1 | non-empty | tenant scope
 *                  | "Closure controls run first"
 *
 * Required, and required with a MINIMUM — "ok" satisfies `required` and tells
 * nobody anything. A closed trip is terminal and cannot be reopened, so the
 * reason is the only account anyone will ever have of why it ended. Twelve
 * characters is the same floor the rejection reason uses (STT-003), for
 * consistency rather than because any document sets one.
 *
 * ── THE WAIVER IS REFUSED, AND THE MESSAGE SAYS WHY THAT IS TEMPORARY ─────
 * BR-P0-017 allows an Owner to waive a failed closure control. Unlike every
 * other override this module refuses, that one IS SPECIFIED — the rule names
 * the waiver and the role. So `waive` is rejected with a message saying the
 * waiver is not built yet, never with one implying no waiver exists.
 */
class CloseTripRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.trip.close). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'closure_reason' => ['required', 'string', 'min:12', 'max:5000'],

            'waive'          => ['prohibited'],
            'waiver_reason'  => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $waiver = 'BR-P0-017 allows an Owner to waive a failed closure control. That waiver is '
            .'specified but not built yet, so there is currently no way to override a closure control.';

        return [
            'closure_reason.required' => 'Say why this trip is being closed — settled in full, written off, or superseded.',
            'closure_reason.min'      => 'Give a little more detail. A closed trip cannot be reopened, and this reason is the only record of why it ended.',
            'waive.prohibited'         => $waiver,
            'waiver_reason.prohibited' => $waiver,
        ];
    }
}
