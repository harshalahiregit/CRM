<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class VendorRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name'   => 'required|string|min:2',
            'last_name'    => 'required|string|min:1',
            // Unique against purchase_vendors, NOT users. This registration no
            // longer creates a login row, so a users check would have guarded a
            // table nothing is written to — two suppliers could then register
            // the same address and neither could be told apart at sign-in.
            'email'        => 'required|email|unique:purchase_vendors,email',
            'company_name' => 'required|string|min:2',
            'password'     => ['required', 'confirmed', Password::min(8)],
            'vendor_type'  => 'required|in:standard,temporary',
            'phone'        => 'nullable|string',
            'designation'  => 'nullable|string',
        ];
    }
}
