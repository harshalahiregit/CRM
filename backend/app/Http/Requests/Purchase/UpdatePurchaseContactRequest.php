<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $phone = ['string', 'regex:/^[0-9+\-\s()]{7,15}$/'];

        // NOTE: purchase_vendor_id is intentionally NOT a rule here — validated()
        // therefore never contains it, so a contact can never be re-parented to a
        // different Purchase Vendor via update.
        return [
            'first_name'       => 'sometimes|required|string|max:100',
            'email'            => 'sometimes|required|email|max:150',
            'phone'            => array_merge(['sometimes', 'required'], $phone),
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
