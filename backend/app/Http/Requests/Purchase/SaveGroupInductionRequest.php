<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One group induction session saved against many Purchase workers — admin and
 * vendor portal alike. The trainer signs ONCE for the whole session.
 *
 * `remarks` is capped at 500 because that is the column
 * (purchase_worker_inductions.remarks is a string(500)); anything longer would
 * be refused by the database for every worker in the group.
 */
class SaveGroupInductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'worker_ids'       => 'required|array|min:1|max:1000',
            'worker_ids.*'     => 'required|integer|distinct',
            'induction_date'   => 'nullable|date',
            'status'           => 'required|in:Completed,Pending',
            'conducted_by'     => 'nullable|string|max:150',
            'remarks'          => 'nullable|string|max:500',
            'trainer_name'     => 'nullable|string|max:150',
            'training_date'    => 'nullable|date',
            'duration_minutes' => 'nullable|integer|between:1,1440',
            'topics'           => 'nullable|array',
            'topics.*'         => 'string|max:120',
            'passed'           => 'nullable|boolean',
            'signature_data'   => ['required', 'string', 'max:2000000', 'regex:/^data:image\/(png|jpe?g);base64,/'],
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
