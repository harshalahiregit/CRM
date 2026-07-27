<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

class SaveWorkerInductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'induction_type'     => 'nullable|string|max:120',
            'trainer'            => 'nullable|string|max:120',
            'trainer_name'       => 'nullable|string|max:120',
            'location'           => 'nullable|string|max:120',
            'induction_location' => 'nullable|string|max:120',
            'start_time'         => 'nullable',
            'end_time'           => 'nullable',
            'induction_start'    => 'nullable',
            'induction_end'      => 'nullable',
            'training_date'      => 'nullable|date',
            'duration_minutes'   => 'nullable|integer|min:1|max:1440',
            'topics'             => 'nullable|array',
            'topics.*'           => 'string|max:120',
            'photo_data'         => 'nullable|string',
            'signature_data'     => 'nullable|string',
            'thumb_data'         => 'nullable|string',
            'score'              => 'nullable|integer|min:0|max:100',
            'passed'             => 'nullable|boolean',
        ];
    }
}
