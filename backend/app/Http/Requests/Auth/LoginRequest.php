<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => 'required|email',
            'password' => 'required|string',
            'role'     => 'required|in:admin,staff,vendor,third_party_vendor,client,company',
            'remember' => 'nullable|boolean',
        ];
    }
}
