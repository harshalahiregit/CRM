<?php

namespace App\Http\Requests\Tpv;

use App\Support\Tpv\TpvContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTpvContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by route middleware (role:admin,staff) and the
        // controller's vendor/tenant guards.
        return true;
    }

    public function rules(): array
    {
        // Lenient phone rule: 7–15 chars of digits, spaces, +, - and parentheses.
        $phone = ['string', 'regex:/^[0-9+\-\s()]{7,15}$/'];

        return [
            // Deliberately the same sixteen fields Purchase accepts, in the
            // same order, with the same rules. The two workspaces capture one
            // kind of record and had drifted: this side asked for twenty-six
            // boxes on screen and accepted nine, so the address, the landline
            // and the notes were typed in and dropped.
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'designation'      => 'required|string|max:120',
            'department'       => 'nullable|string|max:120',
            'email'            => 'required|email|max:150',
            'phone'            => array_merge(['nullable'], $phone),
            'mobile'           => array_merge(['required'], $phone),
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
