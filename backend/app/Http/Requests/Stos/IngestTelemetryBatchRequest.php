<?php

namespace App\Http\Requests\Stos;

use App\Domains\Fleet\Models\VehicleLiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A buffered run of readings from one unit (T-13).
 *
 * A GPS box that loses signal for an hour holds its pings and sends them when
 * the link returns. Posting them one at a time is sixty round trips over the
 * same mobile link that just failed, which is exactly when it is least able to
 * afford them.
 *
 * ── WHY THE PER-ITEM RULES ARE LOOSER THAN THE SINGLE ENDPOINT ────────────
 * Only the two fields that identify a reading are required here: the device and
 * its clock. Everything else is checked by the domain service as each reading is
 * taken, so one dead probe in a buffer of sixty is rejected on its own line and
 * reported back — instead of a 422 that throws away fifty-nine good positions
 * the device has no way to resend separately.
 */
class IngestTelemetryBatchRequest extends FormRequest
{
    /** The door is the device-token middleware, not a user policy. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Capped so a malfunctioning unit cannot post a week of history in
            // one request and hold a worker for the length of it.
            'readings'   => 'required|array|min:1|max:500',

            'readings.*.device_id'   => 'required|string|max:64',
            'readings.*.recorded_at' => 'required|date|before:+10 minutes',

            'readings.*.latitude'    => 'nullable|numeric|between:-90,90',
            'readings.*.longitude'   => 'nullable|numeric|between:-180,180',
            'readings.*.speed'       => 'nullable|numeric|between:0,400',
            'readings.*.ignition'    => 'nullable|boolean',
            'readings.*.temperature' => 'nullable|numeric|between:-60,80',

            'readings.*.generator_status' => ['nullable', Rule::in(array_keys(VehicleLiveStatus::GENERATOR_INPUT))],
        ];
    }

    public function messages(): array
    {
        return [
            'readings.required' => 'The batch does not contain any readings.',
            'readings.max'      => 'Send at most 500 readings per request.',
            'readings.*.recorded_at.before' => 'A reading is dated in the future — check the device clock.',
        ];
    }
}
