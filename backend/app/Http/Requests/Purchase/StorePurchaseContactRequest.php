<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseContactRequest extends FormRequest
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
            // Required: First Name, Email, Phone
            'first_name'       => 'required|string|max:100',
            'email'            => 'required|email|max:150',
            'phone'            => array_merge(['required'], $phone),
            // Optional
            'last_name'        => 'nullable|string|max:100',
            'designation'      => 'nullable|string|max:120',
            'department'       => 'nullable|string|max:120',
            'mobile'           => array_merge(['nullable'], $phone),
            'alternate_mobile' => array_merge(['nullable'], $phone),
            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:120',
            'state'            => 'nullable|string|max:120',
            'country'          => 'nullable|string|max:120',
            'pincode'          => 'nullable|string|max:20',
            'notes'            => 'nullable|string|max:2000',
            'is_primary'       => 'boolean',
            'status'           => ['nullable', Rule::in(PurchaseContactStatus::ALL)],
        ];
    }
}
