<?php

namespace App\Http\Requests\Auth;

use App\Support\Hr\CompanyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Organization
            'company_name'  => 'required|string|max:200',
            'company_type'  => ['nullable', Rule::in(CompanyType::ALL)],
            'industry'      => 'nullable|string|max:120',
            'company_size'  => 'nullable|string|max:40',
            'website'       => 'nullable|string|max:200',
            // Indian GST (15) / PAN (10) formats, validated only when provided.
            'gst_number'    => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'pan_number'    => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            // Company admin (primary login)
            'admin_name'    => 'required|string|max:120',
            'admin_email'   => 'required|email|max:150|unique:users,email',
            'admin_phone'   => 'nullable|string|max:30',
            'password'      => 'required|string|min:8|confirmed',
            'terms'         => 'accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'gst_number.regex' => 'Enter a valid 15-character GST number.',
            'pan_number.regex' => 'Enter a valid 10-character PAN (e.g. ABCDE1234F).',
            'admin_email.unique' => 'An account with this email already exists.',
        ];
    }
}
