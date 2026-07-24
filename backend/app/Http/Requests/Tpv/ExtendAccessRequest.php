<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Extend / renew a Temporary TPV's access window. A reason is mandatory and is
 * audited; either a new expiry or a validity window must be supplied.
 */
class ExtendAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'access_expires_at' => 'nullable|date|required_without:validity_days',
            'validity_days'     => 'nullable|integer|min:1|max:365|required_without:access_expires_at',
            'extension_reason'  => 'required|string|max:255',
        ];
    }
}
