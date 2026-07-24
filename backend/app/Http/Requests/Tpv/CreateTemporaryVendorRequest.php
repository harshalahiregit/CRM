<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin/Staff creates a Temporary TPV. Either an explicit expiry or a validity
 * window must be supplied; the service resolves the window and guards ordering.
 */
class CreateTemporaryVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'      => 'required|string|max:200',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'nullable|string|max:40',
            'access_start_at'   => 'nullable|date',
            'access_expires_at' => 'nullable|date|required_without:validity_days',
            'validity_days'     => 'nullable|integer|min:1|max:365|required_without:access_expires_at',
        ];
    }
}
