<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /transport/trips/{trip}/dispatch — FRS TRP-P0-006.
 *
 * The five fields TRP-P0-006 names, and nothing else. All optional: RTM
 * STOS-REQ-OPS-008's acceptance is only "Dispatch timestamp/status recorded",
 * so a dispatcher who has passed pre-trip can release the trip without filling
 * a planning form. Requiring all five would block a real departure over
 * paperwork the requirement does not demand.
 *
 * `tat` is refused rather than ignored. TRP-P0-006 writes "ETA/TAT" as one
 * field and defines TAT nowhere — ETD→ETA? ETD→return? gate-in→gate-out? A
 * stored number nobody can interpret is the D-9 mistake (`allocation_type`),
 * so it is rejected with the reason instead of silently dropped. The ETD→ETA
 * interval is available as TransportTrip::turnaroundHours().
 */
class DispatchTripRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.trip.dispatch). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_departure_at'  => ['nullable', 'date'],
            // ETA after ETD when both are given. A trip that plans to arrive
            // before it leaves is a typo, and catching it here is cheaper than
            // a negative turnaround downstream.
            'planned_arrival_at'    => ['nullable', 'date', 'after_or_equal:planned_departure_at'],
            'pickup_contact'        => ['nullable', 'string', 'max:190'],
            'dispatch_destination'  => ['nullable', 'string', 'max:190'],
            'dispatch_instructions' => ['nullable', 'string', 'max:5000'],

            'tat' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'planned_arrival_at.after_or_equal' => 'The planned arrival cannot be before the planned departure.',
            'tat.prohibited' => 'Turnaround time is calculated from the planned departure and arrival, not entered. '
                .'The specification does not define its boundaries, so it is not stored.',
        ];
    }
}
