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
            'trainer_name'     => 'nullable|string|max:120',
            'training_date'    => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'topics'           => 'nullable|array',
            'topics.*'         => 'string|max:120',
            'score'            => 'nullable|integer|min:0|max:100',
            'passed'           => 'required|boolean',
        ];
    }
}
