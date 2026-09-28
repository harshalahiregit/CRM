<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * STT-003 — "Reject for correction". The reason IS the precondition.
 *
 * Required here as well as in the service, because the two answer different
 * questions: this one gives the form a field to highlight, the service's one
 * applies however a rejection arrives. A trip sent back with no recorded
 * objection cannot be corrected by the person who receives it.
 */
class RejectTripRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission), not this class's. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Bounded, but not shaped: no document defines a rejection taxonomy,
            // so this is free text rather than an invented list of reasons.
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason for sending this trip back, so whoever corrects it knows what to change.',
            'reason.min'      => 'That reason is too short to be useful to the person correcting the trip.',
        ];
    }
}
