<?php

namespace App\Http\Requests\Compliance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used by BOTH the authenticated and the public token routes, so it must not
 * assume a logged-in user.
 *
 * Note what is absent: score, max_score, risk_band. Those are computed by
 * ChecklistEvaluator on submit and are not accepted from the client — a filler
 * must not be able to post their own risk band.
 */
class SubmitChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Answer shape is per-question and template-driven, so it can only be
            // meaningfully validated by the evaluator against the definition.
            //
            // Optional because a submission carrying a selfie must be multipart,
            // and multipart stringifies everything — a boolean answer would
            // arrive as "true" and fail the evaluator's is_bool check. Clients
            // therefore save answers as JSON first (types intact) and submit the
            // media separately; the service falls back to the saved responses.
            // Nothing is lost: submit still validates the full set and refuses an
            // incomplete checklist.
            'responses' => 'nullable|array',

            // Field capture. GPS is best-effort — a phone indoors on a site may
            // legitimately have no fix, and refusing the submission for that
            // would just push people to fill the form in the car park.
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'selfie'    => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'selfie.max'   => 'The selfie must be 5 MB or smaller.',
            'selfie.image' => 'The selfie must be an image.',
        ];
    }
}
