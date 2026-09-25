<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitQuestionnaireResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             | Tenant-scoped, and it has to be.
             |
             | The rule was `exists:lead_questionnaires,id` — any id in the table,
             | belonging to anybody. A tenant could bind another tenant's
             | questionnaire to their own lead, and because the lead profile
             | eager-loads questionnaireResponses.questionnaire, the other
             | tenant's form title would then be rendered back to them. The
             | response row's own tenant_id is set correctly, which is what made
             | it invisible: nothing looked wrong except the title.
             |
             | It had never been reachable — no screen called this endpoint until
             | SIR-000035 — so the hole had never been walked through. It is being
             | closed in the same change that opens the door.
             */
            'questionnaire_id' => [
                'required',
                Rule::exists('lead_questionnaires', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            'answers'          => 'required|array',
        ];
    }
}
