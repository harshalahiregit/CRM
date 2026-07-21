<?php

namespace App\Http\Requests\Vendor;

use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'        => 'required|string',
            'legal_name'          => 'nullable|string',
            'vendor_type'         => 'required|in:standard,temporary',
            'engagements'         => 'required|array|min:1',
            'engagements.*'       => 'in:purchase,tpv',

            // Optional portal login (the "Add Third-party Vendor" flow). When a
            // password is present, a third_party_vendor User is created & linked.
            'name'                => 'nullable|string|max:150',
            'password'            => 'nullable|string|min:6|confirmed',
            'email'               => 'nullable|email',
            'phone'               => 'nullable|string',
            'website'             => 'nullable|url',
            'category'            => 'nullable|string',
            'registration_number' => 'nullable|string',
            'gst_number'          => 'nullable|string',
            'pan_number'          => 'nullable|string',
            'address'             => 'nullable|string',
            'city'                => 'nullable|string',
            'state'               => 'nullable|string',
            'country'             => 'nullable|string',
            'pincode'             => 'nullable|string',
            'account_manager_id'  => 'nullable|integer|exists:users,id',
            'status'              => ['nullable', Rule::in(VendorStatus::ALL)],
            'notes'               => 'nullable|string',

            'contacts'              => 'nullable|array',
            'contacts.*.name'       => 'required|string',
            'contacts.*.designation'=> 'nullable|string',
            'contacts.*.email'      => 'nullable|email',
            'contacts.*.phone'      => 'nullable|string',
            'contacts.*.is_primary' => 'nullable|boolean',
        ];
    }
}
