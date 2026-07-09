<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_id'   => 'nullable|exists:hr_candidates,id',
            'candidate_name' => 'required|string',
            'position'       => 'required|string',
            'joining_date'   => 'required|date',
            'department'     => 'nullable|string',
        ];
    }
}
