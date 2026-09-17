<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refusing a trip document — SNG-TRN-014, DB-009.
 *
 * A separate request class rather than an inline `$request->validate()`, because
 * TEAM-CONVENTIONS puts validation in the request layer and D-47's analysis
 * records that as the thing this module already complies with.
 *
 * `reason` is required and the service checks it is not merely whitespace.
 * Rejecting a POD is terminal — the next attempt is a new row — so the reason is
 * the only explanation anybody will ever have for why the first one failed.
 */
class RejectTripDocumentRequest extends FormRequest
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
            'reason.required' => 'A reason is required to reject a document.',
        ];
    }
}
