<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving or refusing an advance — PERM-007.
 *
 * `amount_approved` is optional and means "approve for less than was asked".
 * Omitted, the requested figure stands. The service refuses a figure larger
 * than the request, so this cannot become a way to issue more money than
 * anybody asked for.
 *
 * `decision_reason` is required on a rejection and optional on an approval, and
 * that asymmetry is deliberate: BRWM §70 asks a refusal to tell the person what
 * would resolve it, while an approval that matches the request explains itself.
 * The requirement is enforced in the service rather than here, because a
 * rejection can also arrive from a path that does not use this request.
 */
class DecideTripAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_approved' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
            'decision_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_approved.gt' => 'An approved advance must be greater than zero. Reject it instead.',
        ];
    }
}
