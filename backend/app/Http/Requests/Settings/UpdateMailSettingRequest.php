<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'host'       => 'nullable|string|max:255',
            'port'       => 'nullable|integer|min:1|max:65535',
            'username'   => 'nullable|string|max:255',
            // Blank/absent password = keep the currently stored one.
            'password'   => 'nullable|string|max:255',
            'encryption' => 'nullable|in:tls,ssl,none',
            'from_name'  => 'nullable|string|max:255',
            'from_email' => 'nullable|email|max:255',
            'reply_to'   => 'nullable|email|max:255',
            'enabled'    => 'nullable|boolean',
            // Off = accept a self-signed / mismatched mail-server certificate.
            'verify_peer' => 'nullable|boolean',
        ];
    }
}
