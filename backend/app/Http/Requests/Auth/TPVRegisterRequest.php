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
        ];
    }
}
