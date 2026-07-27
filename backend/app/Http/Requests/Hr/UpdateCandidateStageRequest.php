<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Manual "Move Stage" is only allowed between the pre-selection stages.
            // Offer & Hired are driven by the recruitment workflow (onboarding
            // approval → Offer, offer acceptance → Hired) and are rejected here.
            'stage' => 'required|in:Applied,Screening,Assessment,Interview',
        ];
    }

    public function messages(): array
    {
        return [
            'stage.in' => 'Offer and Hired stages are managed automatically by the recruitment workflow.',
        ];
    }
}
