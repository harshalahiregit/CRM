<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /transport/trips/{trip}/depart — STT-006, RTM STOS-REQ-OPS-009.
 *
 * ONE OPTIONAL FIELD, and that is the owner's Q3 limit taken literally:
 * "departed_at, departed_by columns only. Nothing beyond that."
 *
 * `departed_by` is the authenticated user, never a request field — letting a
 * caller name someone else as the person who released the vehicle would make
 * the audit trail a suggestion.
 *
 * `departed_at` is optional because the common case is a dispatcher pressing
 * the button as the truck pulls out. Backdating is allowed and is checked in
 * DispatchService::departureTime() rather than here, because the bound it must
 * respect — not before `dispatched_at` — is a fact about the record, not about
 * the payload.
 *
 * The three fields below are REFUSED rather than ignored. Each is named in some
 * document as something a departing vehicle has, and each is exactly what Q3
 * excluded. Silently dropping them would let a client believe it had recorded
 * an odometer reading; D-9 is what happens when a field exists with nothing
 * behind it.
 */
class RecordDepartureRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.trip.dispatch). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'departed_at' => ['nullable', 'date'],

            'odometer'    => ['prohibited'],
            'location'    => ['prohibited'],
            'seal_number' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $q3 = ' The owner\'s ruling of 2026-09-10 limits a recorded departure to its time and the person who recorded it.';

        return [
            'odometer.prohibited'    => 'Odometer readings are not recorded here.'.$q3,
            'location.prohibited'    => 'A departure location needs GPS, which is not built (SNG-TRN-020).'.$q3,
            'seal_number.prohibited' => 'Seal numbers belong to the consignment, not to the departure.'.$q3,
        ];
    }
}
