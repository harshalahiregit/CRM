<?php

namespace App\Http\Requests\Tpv;

use App\Rules\Gstin;
use App\Rules\Ifsc;
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
            // wizard can evolve its fields without a migration each time. All keys
            // are optional so partial (draft) saves and legacy profiles stay valid.
            'profile'                    => 'required|array',

            // ── Existing (legacy) keys — unchanged, kept valid ──────────────
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

            // ── Company Details ─────────────────────────────────────────────
            'profile.company_name'                => 'nullable|string|max:200',
            'profile.legal_name'                  => 'nullable|string|max:200',
            'profile.company_registration_number' => 'nullable|string|max:100',
            'profile.category'                    => 'nullable|string|max:120',
            'profile.company_phone'               => 'nullable|string|max:40',

            // ── Contact Details ─────────────────────────────────────────────
            'profile.contact_email'  => 'nullable|email',
            'profile.contact_mobile' => 'nullable|string|max:40',

            // ── Authorized Person ───────────────────────────────────────────
            'profile.authorized_name'        => 'nullable|string|max:160',
            'profile.authorized_designation' => 'nullable|string|max:120',
            'profile.authorized_email'       => 'nullable|email',
            'profile.authorized_mobile'      => 'nullable|string|max:40',
            'profile.authorized_id_proof'    => 'nullable|string|max:120',

            // ── Bank Details (IFSC required with account number & vice versa) ─
            'profile.bank_account_holder' => 'nullable|string|max:160',
            'profile.bank_name'           => 'nullable|string|max:160',
            'profile.bank_account_number' => ['nullable', 'required_with:profile.bank_ifsc', 'digits_between:9,18'],
            'profile.bank_ifsc'           => ['nullable', 'required_with:profile.bank_account_number', new Ifsc],
            'profile.bank_branch'         => 'nullable|string|max:160',
            'profile.bank_account_type'   => 'nullable|string|in:Savings,Current',

            // ── GST & PAN ───────────────────────────────────────────────────
            'profile.gst_number' => ['nullable', new Gstin],
            'profile.gst_state'  => 'nullable|string|max:120',
            'profile.pan_number' => ['nullable', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],

            // ── Personal & Phase 4 Fields ──────────────────────────────────
            'profile.full_name'          => 'nullable|string|max:200',
            'profile.email'              => 'nullable|email',
            'profile.mobile'             => 'nullable|string|max:40',
            'profile.alt_mobile'         => 'nullable|string|max:40',
            'profile.gender'             => 'nullable|string|in:Male,Female,Other',
            'profile.profile_photo'      => 'nullable|string',
            'profile.company_reg_date'   => 'nullable|date',
            'profile.registration_date'  => 'nullable|date',

            // ── Social Profiles ─────────────────────────────────────────────
            'profile.facebook'   => 'nullable|string|max:250',
            'profile.twitter'    => 'nullable|string|max:250',
            'profile.instagram'  => 'nullable|string|max:250',
            'profile.youtube'    => 'nullable|string|max:250',
            'profile.portfolio'  => 'nullable|string|max:250',

            // ── Registered Address ──────────────────────────────────────────
            'profile.city'    => 'nullable|string|max:120',
            'profile.state'   => 'nullable|string|max:120',
            'profile.country' => 'nullable|string|max:120',
            'profile.pincode' => 'nullable|digits:6',
        ];
    }
}
