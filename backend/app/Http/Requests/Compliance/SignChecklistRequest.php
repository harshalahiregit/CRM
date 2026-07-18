<?php

namespace App\Http\Requests\Compliance;

use App\Support\Compliance\SignatureTier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A manager/head acting on a submitted checklist.
 *
 * The tier is NOT taken from the body — it comes from the route, so a caller
 * cannot claim to be the head tier. Whether they may act at all is the route's
 * role gate; whether the checklist is at the right stage is the service's.
 */
class SignChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action'  => 'required|string|in:'.SignatureTier::APPROVE.','.SignatureTier::REJECT,
            // Requiredness on reject is a business rule (ComplianceChecklistService::act)
            // so the message stays specific rather than a generic 422.
            'remarks' => 'nullable|string|max:1000',
            'signature' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',

            // Knowingly sign off your own issue. Who may do this, and on what
            // terms, is a business rule — the service decides, not this shape check.
            'override_segregation' => 'nullable|boolean',
        ];
    }
}
