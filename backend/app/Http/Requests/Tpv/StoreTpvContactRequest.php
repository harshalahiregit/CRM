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
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'designation'      => 'required|string|max:120',
            'department'       => 'nullable|string|max:120',
            'email'            => 'required|email|max:150',
            'mobile'           => array_merge(['required'], $phone),
            'alternate_mobile' => array_merge(['nullable'], $phone),
            'is_primary'       => 'boolean',
            'status'           => ['nullable', Rule::in(TpvContactStatus::ALL)],
        ];
    }
}
