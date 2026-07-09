<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ClientRegisterRequest extends FormRequest
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
            'company'    => 'required|string|min:2',
            'phone'      => 'required|string|min:7',
            'password'   => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
