<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class SubmitQuestionnaireResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'questionnaire_id' => 'required|exists:lead_questionnaires,id',
            'answers'          => 'required|array',
        ];
    }
}
