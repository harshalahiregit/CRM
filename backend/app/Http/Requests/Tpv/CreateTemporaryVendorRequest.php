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
            // Unique across both logins and vendor rows — a temporary vendor for an
            // email that already has a vendor profile (but no user yet) would
            // otherwise pass here and then collide on User::create.
            'email'             => 'required|email|unique:users,email|unique:vendors,email',
            'phone'             => 'nullable|string|max:40',
            // Optional: admin may set the portal password, else one is generated and
            // returned once. min:8 matches the standard vendor create flow.
            'password'          => 'nullable|string|min:8',
            'access_start_at'   => 'nullable|date',
            'access_expires_at' => 'nullable|date|required_without:validity_days',
            'validity_days'     => 'nullable|integer|min:1|max:365|required_without:access_expires_at',
        ];
    }
}
