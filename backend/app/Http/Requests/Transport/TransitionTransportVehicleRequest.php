<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FLEET §8 — "Vehicle status must be driven by business events. Users should not
 * freely type 'Available'."
 *
 * So status moves through this endpoint and never through update(). Whether the
 * specific move is legal is VehicleStatus::canTransition's job, not this class's
 * — it validates that the value is a status at all.
 */
class TransitionTransportVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(VehicleStatus::ALL)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
