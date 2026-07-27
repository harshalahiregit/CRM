<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 5 — Final Confirmation. The vendor must accept the declaration before the
 * onboarding can be finished. Tenant + workflow gates are enforced in the
 * controller/service; this only checks the declaration was accepted.
 */
class SubmitOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'declaration' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'declaration.required' => 'You must accept the declaration to finish onboarding.',
            'declaration.accepted' => 'You must accept the declaration to finish onboarding.',
        ];
    }
}
