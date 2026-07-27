<?php

namespace App\Http\Requests;

use App\Services\Hr\OnboardingService;
use Illuminate\Foundation\Http\FormRequest;

class SubmitOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // JSON-encoded structured details (personal_details, address,
            // emergency_contact, education, experience, bank_details) — decoded
            // in the controller so it can travel alongside file uploads.
            'submission'  => 'nullable|string',
            // Documents keyed by type: aadhaar, pan, resume, photo, address_proof, …
            'documents'   => 'nullable|array',
            'documents.*' => 'file|mimes:'.implode(',', OnboardingService::ALLOWED_MIMES).'|max:'.OnboardingService::MAX_SIZE_KB,
        ];
    }
}
