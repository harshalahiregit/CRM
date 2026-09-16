<?php

namespace App\Http\Requests\Medical;

use App\Support\Medical\MedicalQcStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A quality-check verdict.
 *
 * The reason is required for a rejection or a hold, and required HERE rather
 * than only in the service, so the message a reviewer sees names the field they
 * left empty. A refusal nobody can act on is not a review — that rule is the
 * point of this class.
 */
class MedicalQcDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $needsReason = in_array($this->input('decision'), MedicalQcStatus::REASON_REQUIRED, true);

        return [
            'decision'    => ['required', Rule::in(MedicalQcStatus::DECISIONS)],
            // Either a catalogued reason or a written one — a reviewer who picks
            // "other" still has to say what they mean.
            'reason_code' => [$needsReason ? 'required_without:note' : 'nullable', 'string', 'max:60'],
            'note'        => [$needsReason ? 'required_without:reason_code' : 'nullable', 'string', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required_without' => 'Give a reason — a rejection or hold without one cannot be acted on.',
            'note.required_without'        => 'Give a reason — a rejection or hold without one cannot be acted on.',
        ];
    }
}
