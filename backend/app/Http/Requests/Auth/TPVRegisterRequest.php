<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class TPVRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|min:2',
            'last_name'  => 'required|string|min:1',
            'email'      => 'required|email|unique:users,email',
            'password'   => ['required', 'confirmed', Password::min(8)],
            'tpv_type'   => 'required|in:permanent,temporary',
            'username'   => 'required|string|unique:users,meta->username',
            'phone'      => 'nullable|string',
            'industry'   => 'nullable|string',
            'position'   => 'nullable|string',
            // Company identity captured up front, so the vendor record is complete
            // from registration rather than typed in again by an admin later.
            // Optional: a vendor part-way through the form must still be able to
            // register, and an admin can fill the rest on the profile tab.
            'legal_name' => 'nullable|string|max:200',
            // Same PAN shape the onboarding profile enforces (AAAAA9999A).
            'pan_number' => ['nullable', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
            'address'    => 'nullable|string|max:500',
        ];
    }
}
