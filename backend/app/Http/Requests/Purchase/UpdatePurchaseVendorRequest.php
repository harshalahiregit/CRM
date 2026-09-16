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
            // The person we deal with, and the company details self-registration
            // collects. Free text for manpower and MSME — suppliers answer
            // "50-100" and "Yes" as readily as a number or a code.
            'contact_person'      => 'nullable|string|max:150',
            'contact_designation' => 'nullable|string|max:120',
            'company_phone'       => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-()\s]{6,30}$/'],
            'manpower'            => 'nullable|string|max:60',
            'msme'                => 'nullable|string|max:120',
            'gst_number'          => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z]{1,20}$/'],
            /*
             * `sometimes|required` means "if the key is present it must have a
             * value" — and the edit form posts the whole record, so a vendor
             * whose category was already null sent category:null and was
             * refused. The thirteen-field form does not ask for a category, so
             * there was no field on screen to fix it in: the save simply failed
             * with "The category field is required" pointing at nothing.
             *
             * Vendors with a null category already existed before any of this —
             * they arrive through self-registration and conversion — so the rule
             * was rejecting records the table already held. Both fields stay
             * editable on the vendor's Profile tab. See the Store request.
             */
            'category'            => 'nullable|string|max:120',
            'currency'            => 'nullable|in:INR,USD,EUR',
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
