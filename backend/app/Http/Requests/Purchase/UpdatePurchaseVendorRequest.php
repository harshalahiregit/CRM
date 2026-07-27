<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'        => 'sometimes|required|string|max:200',
            'legal_name'          => 'nullable|string|max:200',
            'vendor_type'         => 'sometimes|in:standard,temporary',
            'email'               => 'nullable|email|max:150',
            // Format-validated optional fields (§8)
            'phone'               => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-()\s]{6,30}$/'],
            'website'             => ['nullable', 'string', 'max:200', 'regex:~^(https?://)?([\w-]+\.)+[\w-]{2,}(/\S*)?$~i'],
            'gst_number'          => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]{1,20}$/'],
            // Required when present per §8 (Company, Vendor Category, Currency)
            'category'            => 'sometimes|required|string|max:120',
            'currency'            => 'sometimes|required|in:INR,USD,EUR',
            'language'            => 'nullable|in:System Default,English',
            // Profile / financial (Purchase-owned)
            'balance'             => 'nullable|numeric',
            'balance_as_of'       => 'nullable|date',
            'bank_details'        => 'nullable|string|max:2000',
            'payment_terms'       => 'nullable|string|max:120',
            'return_policy'       => 'nullable|string|max:5000',
            'registration_number' => 'nullable|string|max:120',
            'pan_number'          => 'nullable|string|max:30',
            'address'             => 'nullable|string|max:255',
            'city'                => 'nullable|string|max:120',
            'state'               => 'nullable|string|max:120',
            'country'             => 'nullable|string|max:120',
            'pincode'             => 'nullable|string|max:20',
            'account_manager_id'  => 'nullable|integer',
            'notes'               => 'nullable|string',
        ];
    }
}
