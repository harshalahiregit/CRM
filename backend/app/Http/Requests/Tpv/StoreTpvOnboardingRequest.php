<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

class StoreTpvOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Tenant ownership + duplicate-onboarding checks live in the service.
            'vendor_id'          => 'required|integer|exists:vendors,id',
            'kickoff_meeting_id' => 'nullable|integer',
            'remarks'            => 'nullable|string',
        ];
    }
}
