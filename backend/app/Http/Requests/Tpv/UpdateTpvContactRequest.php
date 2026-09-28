<?php

namespace App\Http\Requests\Tpv;

use App\Support\Tpv\TpvContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTpvContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $phone = ['string', 'regex:/^[0-9+\-\s()]{7,15}$/'];

        return [
            // Same sixteen as the store request and as Purchase — an edit that
            // accepts fewer fields than the create silently drops them on save.
            'first_name'       => 'sometimes|required|string|max:100',
            'last_name'        => 'sometimes|required|string|max:100',
            'designation'      => 'sometimes|required|string|max:120',
            'department'       => 'nullable|string|max:120',
            'email'            => 'sometimes|required|email|max:150',
            'phone'            => array_merge(['nullable'], $phone),
            'mobile'           => array_merge(['sometimes', 'required'], $phone),
            'alternate_mobile' => array_merge(['nullable'], $phone),
            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:120',
            'state'            => 'nullable|string|max:120',
            'country'          => 'nullable|string|max:120',
            'pincode'          => 'nullable|string|max:20',
            'notes'            => 'nullable|string|max:2000',
            'is_primary'       => 'boolean',
            'status'           => ['nullable', Rule::in(TpvContactStatus::ALL)],
        ];
    }
}
