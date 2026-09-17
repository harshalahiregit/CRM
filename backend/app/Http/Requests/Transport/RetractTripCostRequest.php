<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking a cost back out of a trip's margin — SNG-TRN-012, DB-006.
 *
 * A separate request class rather than an inline `$request->validate()` in the
 * controller, because TEAM-CONVENTIONS puts validation in the request layer and
 * the analysis behind D-47 records that as the thing this module already
 * complies with: "FormRequest → service → ApiResponse", every controller thin.
 * An inline rule is the one that drifts from the rest.
 *
 * `reason` is required and the service checks it is not merely whitespace. A
 * retraction changes a reported figure, and SNG-TRN-018 has to be able to say
 * why a margin moved — "deleted" on its own is not an explanation.
 */
class RetractTripCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to retract a cost.',
        ];
    }
}
