<?php

namespace App\Http\Requests\Vendor;

use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'        => 'sometimes|required|string',
            'legal_name'          => 'nullable|string',
            'vendor_type'         => 'sometimes|required|in:standard,temporary',
            'engagements'         => 'sometimes|required|array|min:1',
            'engagements.*'       => 'in:purchase,tpv',
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
