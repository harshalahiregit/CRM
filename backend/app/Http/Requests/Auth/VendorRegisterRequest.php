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
            'email'        => 'required|email|unique:users,email',
            'company_name' => 'required|string|min:2',
            'password'     => ['required', 'confirmed', Password::min(8)],
            'vendor_type'  => 'required|in:standard,temporary',
            'phone'        => 'nullable|string',
            'designation'  => 'nullable|string',
        ];
    }
}
