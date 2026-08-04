<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Review comment #17 — "Start onboarding – linked with candidate database, no
 * direct candidate entry".
 *
 * `candidate_id` is REQUIRED. It was nullable while `candidate_name` was a
 * required free-text field, which is precisely the "direct candidate entry" the
 * comment rules out: anyone could start an onboarding for a person the candidate
 * database had never heard of, orphaning the whole recruitment trail.
 *
 * `candidate_name` is now optional and derived from the candidate when omitted,
 * so the name on the record can no longer disagree with the candidate it points at.
 */
class StoreOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Scoped to the caller's tenant, not a bare `exists:`. A bare rule
            // admits ANOTHER tenant's candidate id: the row exists, so validation
            // passes, the tenant-scoped lookup in the service then finds nothing,
            // and the insert dies on a NOT NULL candidate_name — a 500 where the
            // honest answer is 422. It is also the shape of a cross-tenant probe.
            'candidate_id'   => [
                'required',
                Rule::exists('hr_candidates', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
            // Kept accepted for the existing caller that already sends it; the
            // service fills it from the candidate when it is absent.
            'candidate_name' => 'nullable|string',
            'position'       => 'required|string',
            'joining_date'   => 'required|date',
            'department'     => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'candidate_id.required' => 'Pick the candidate this onboarding is for — onboarding always starts from the candidate database.',
        ];
    }
}
