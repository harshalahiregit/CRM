<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Amend a DRAFT trip's commercial fields. The service refuses anything past draft.
 *
 * status is absent on purpose: it moves through the transition endpoint only, so
 * the state machine cannot be bypassed by a general update.
 */
class UpdateTransportTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_freight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'currency'         => ['sometimes', 'string', 'size:3'],
            'route'            => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}
