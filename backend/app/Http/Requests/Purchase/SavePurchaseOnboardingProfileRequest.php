<?php

namespace App\Http\Requests\Purchase;

use App\Rules\Gstin;
use App\Rules\Ifsc;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step-2 company/contact profile for Purchase-vendor onboarding. Mirrors the TPV
 * profile shape (kept a separate class for module isolation). Free-form within a
 * bounded shape; all keys optional so partial (draft) saves stay valid.
 */
class SavePurchaseOnboardingProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile' => 'required|array',

            'profile.contact_person'      => 'nullable|string',
            'profile.designation'         => 'nullable|string',
            'profile.registered_address'  => 'nullable|string',
            'profile.website'             => 'nullable|string',
            'profile.scope_of_work'       => 'nullable|string',

            'profile.company_name'                => 'nullable|string|max:200',
            'profile.legal_name'                  => 'nullable|string|max:200',
            'profile.company_registration_number' => 'nullable|string|max:100',
            'profile.category'                    => 'nullable|string|max:120',
            'profile.company_phone'               => 'nullable|string|max:40',

            'profile.contact_email'  => 'nullable|email',
            'profile.contact_mobile' => 'nullable|string|max:40',

            'profile.authorized_name'        => 'nullable|string|max:160',
            'profile.authorized_designation' => 'nullable|string|max:120',
            'profile.authorized_email'       => 'nullable|email',
            'profile.authorized_mobile'      => 'nullable|string|max:40',

            'profile.bank_account_holder' => 'nullable|string|max:160',
            'profile.bank_name'           => 'nullable|string|max:160',
            'profile.bank_account_number' => ['nullable', 'required_with:profile.bank_ifsc', 'digits_between:9,18'],
            'profile.bank_ifsc'           => ['nullable', 'required_with:profile.bank_account_number', new Ifsc],
            'profile.bank_branch'         => 'nullable|string|max:160',
            'profile.bank_account_type'   => 'nullable|string|in:Savings,Current',

            'profile.gst_number' => ['nullable', new Gstin],
            'profile.gst_state'  => 'nullable|string|max:120',
            'profile.pan_number' => ['nullable', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],

            'profile.full_name'  => 'nullable|string|max:200',
            'profile.email'      => 'nullable|email',
            'profile.mobile'     => 'nullable|string|max:40',
            'profile.alt_mobile' => 'nullable|string|max:40',

            'profile.city'    => 'nullable|string|max:120',
            'profile.state'   => 'nullable|string|max:120',
            'profile.country' => 'nullable|string|max:120',
            'profile.pincode' => 'nullable|digits:6',
        ];
    }
}
