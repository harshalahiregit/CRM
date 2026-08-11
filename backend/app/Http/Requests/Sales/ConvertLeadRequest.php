<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Everything is optional: the lead's own details are the defaults, and
            // the convert dialog only sends what the user corrected.
            'company'       => 'nullable|string|max:255',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'phone'         => 'nullable|string|max:50',
            'website'       => 'nullable|string|max:255',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string|max:120',
            'state'         => 'nullable|string|max:120',
            'zip'           => 'nullable|string|max:20',
            'country'       => 'nullable|string|max:120',

            // Opt-in carry-overs, mirroring the old CRM's convert checkboxes.
            'transfer_notes'         => 'nullable|boolean',
            'transfer_custom_fields' => 'nullable|boolean',

            // Kept for older callers that still post these two.
            'firstname' => 'nullable|string|max:255',
            'lastname'  => 'nullable|string|max:255',
            'email'     => 'nullable|email|max:255',
        ];
    }
}
