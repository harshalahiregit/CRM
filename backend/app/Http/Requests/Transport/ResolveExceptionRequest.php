<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /transport/exceptions/{exception}/resolve — STT-016.
 *
 * BR-P0-011 is a HARD rule: "No critical exception can be marked resolved
 * without resolution evidence." The owner's Q4 ruling made that evidence
 * note-only, because BR-P0-011 asks for "note/photo/document" and the other two
 * need file upload, which Transport does not have (D-22).
 *
 * The note is required for EVERY severity, not only critical. Being stricter
 * than a Hard rule is safe; being looser is not, and an exception resolved with
 * no account of how tells the next reader nothing whatever its severity.
 */
class ResolveExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'min:12', 'max:5000'],

            // D-30, and worded so nobody reads it as "no waiver exists".
            'waive'         => ['prohibited'],
            'waiver_reason' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $waiver = 'BR-P0-011 allows an Owner to waive the evidence requirement. That waiver is '
            .'specified but not built yet, so there is currently no way to resolve without a note.';

        return [
            'resolution_note.required' => 'Say how it was resolved. BR-P0-011 requires resolution evidence, and a note is the evidence this build can take.',
            'resolution_note.min'      => 'Give a little more detail — this is the record of what actually fixed it.',
            'waive.prohibited'         => $waiver,
            'waiver_reason.prohibited' => $waiver,
        ];
    }
}
