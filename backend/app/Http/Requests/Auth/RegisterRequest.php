<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|min:2|max:100',
            'email'    => 'required|email|unique:users,email',
            'company'  => 'required|string|min:2|max:150',
            'password' => ['required', 'confirmed', Password::min(8)],
            'terms'    => 'accepted',
        ];
    }
}
