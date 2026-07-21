<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

class SaveOnboardingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // TPV-specific step-2 profile. Free-form within a bounded shape so the
            // wizard can evolve its fields without a migration each time.
            'profile'                    => 'required|array',
            'profile.contact_person'     => 'nullable|string',
            'profile.designation'        => 'nullable|string',
            'profile.dob'                => 'nullable|date',
            'profile.emergency_contact'  => 'nullable|string',
            'profile.emergency_phone'    => 'nullable|string',
            'profile.registered_address' => 'nullable|string',
            'profile.website'            => 'nullable|string',
            'profile.linkedin'           => 'nullable|string',
            'profile.estimated_workforce' => 'nullable|integer|min:0',
            'profile.scope_of_work'      => 'nullable|string',
        ];
    }
}
