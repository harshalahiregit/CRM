<?php

namespace App\Http\Requests\Stos;

use App\Domains\Fleet\Models\VehicleLiveStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The hardware payload. All validation lives here; the controller stays thin
 * and the domain service receives data it can trust (golden rule 4).
 *
 * Everything but the device, its clock and nothing else is optional: a unit
 * with a dead temperature probe still reports position, and dropping the whole
 * ping because one sensor is silent loses the trail.
 */
class IngestTelemetryRequest extends FormRequest
{
    /** The door is the device-token middleware, not a user policy. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => 'required|string|max:64',

            // Ranges, not just "numeric": a device with a dead GPS fix reports
            // 0,0 or 999, and storing that silently puts trucks in the Atlantic.
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'speed'     => 'nullable|numeric|between:0,400',
            'ignition'  => 'nullable|boolean',

            'generator_status' => ['nullable', Rule::in(VehicleLiveStatus::GENERATOR_STATES)],

            // A reefer body runs to -40; an engine bay reads high. Anything
            // outside this is a faulty probe, not a reading.
            'temperature' => 'nullable|numeric|between:-60,80',

            // The DEVICE's clock. A small future skew is normal and tolerated;
            // an hour into the future is a misconfigured unit whose readings
            // would sit at the top of every "latest" query forever.
            'recorded_at' => 'required|date|before:+10 minutes',
        ];
    }

    public function messages(): array
    {
        return [
            'recorded_at.before' => 'The reading is dated in the future — check the device clock.',
            'device_id.required' => 'The payload does not say which device sent it.',
        ];
    }
}
