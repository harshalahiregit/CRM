<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'        => 'required|string|max:200',
            'legal_name'          => 'nullable|string|max:200',
            // Mandatory: Permanent (standard) or Temporary. Matches StoreVendorRequest
            // so Purchase and TPV enforce the same rule.
            'vendor_type'         => 'required|in:standard,temporary',
            'registration_type'   => ['nullable', Rule::in(\App\Support\Purchase\PurchaseRegistrationType::ALL)],
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
            // Required per §8: Company, Vendor Category, Currency
            'category'            => 'required|string|max:120',
            'currency'            => 'required|in:INR,USD,EUR',
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
            'status'              => ['nullable', Rule::in(PurchaseVendorStatus::ALL)],
            // The first portal password. Optional: left blank, the system
            // generates one. Either way the vendor is e-mailed what it is,
            // because that message is the only place it exists in readable form
            // — nothing on the admin screen can show it afterwards.
            'password'            => 'nullable|string|min:8|max:100',
        ];
    }
}
