<?php

namespace App\Http\Requests\Tpv;

/**
 * One group induction session saved against many workers.
 *
 * The session fields are the single-worker request's, unchanged — the group
 * endpoint is the same save repeated, not a second definition of an induction.
 * What it adds is the worker list, and it makes the TRAINER's signature
 * mandatory: in a group session the trainer signs once for everybody, instead
 * of every worker signing a canvas of their own.
 */
class SaveGroupInductionRequest extends SaveWorkerInductionRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'worker_ids'     => 'required|array|min:1|max:1000',
            'worker_ids.*'   => 'required|integer|distinct',
            // A drawn signature, as the canvas hands it over.
            'signature_data' => ['required', 'string', 'max:2000000', 'regex:/^data:image\/(png|jpe?g);base64,/'],
        ];
    }

    public function messages(): array
    {
        return [
            'signature_data.required' => 'The trainer must sign the session before it can be saved.',
            'signature_data.regex'    => 'The trainer signature must be a PNG or JPEG image.',
            'worker_ids.max'          => 'A group session can hold at most 1000 workers at a time.',
        ];
    }
}
