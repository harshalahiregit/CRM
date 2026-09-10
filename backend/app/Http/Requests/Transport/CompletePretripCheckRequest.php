<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /transport/trips/{trip}/prechecks/{check} — SNG-TRN-010 step 7.
 *
 * The per-item form of Step 5's `check_items`, which the screen uses: a
 * supervisor works down the list confirming one row at a time rather than
 * assembling a batch. PATCH because this changes one field of one existing
 * record, matching the two transition endpoints already in this module
 * (PATCH /trips/{id}/submit-viability, PATCH /vehicles/{id}/status).
 *
 * Only a remark is accepted. See SubmitPretripChecksRequest for why a result
 * is not: confirming a check is not the same act as overriding it, and override
 * is P1.
 */
class CompletePretripCheckRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission:transport.pretrip.perform). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks'  => ['nullable', 'string', 'max:2000'],
            'result'   => ['prohibited'],
            'evidence' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'result.prohibited' => 'A pre-trip result is calculated, not entered. '
                .'Confirm the check, or resolve what it reports and refresh the checklist.',
            'evidence.prohibited' => 'Photo evidence cannot be attached yet — Transport has no document upload. '
                .'Record what you saw in the check remarks instead.',
        ];
    }
}
