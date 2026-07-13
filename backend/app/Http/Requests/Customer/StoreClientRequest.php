<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company'          => 'required|string|max:255',
            'gst_number'       => 'nullable|string|max:30',
            'phone'            => 'nullable|string|max:30',
            'website'          => 'nullable|string|max:255',
            'parent_company'   => 'nullable|string|max:255',
            'opening_balance'  => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'show_primary_contact' => 'nullable|boolean',
            'vendor_id'        => 'nullable|integer',
            'lead_id'          => 'nullable|integer',

            'address'          => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:100',
            'state'            => 'nullable|string|max:100',
            'zip'              => 'nullable|string|max:20',
            'country'          => 'nullable|string|max:100',

            'billing_street'   => 'nullable|string|max:255',
            'billing_city'     => 'nullable|string|max:100',
            'billing_state'    => 'nullable|string|max:100',
            'billing_zip'      => 'nullable|string|max:20',
            'billing_country'  => 'nullable|string|max:100',
            'shipping_street'  => 'nullable|string|max:255',
            'shipping_city'    => 'nullable|string|max:100',
            'shipping_state'   => 'nullable|string|max:100',
            'shipping_zip'     => 'nullable|string|max:20',
            'shipping_country' => 'nullable|string|max:100',

            'social_links'     => 'nullable|array',
            'foundation_date'  => 'nullable|date',
            'dob'              => 'nullable|date',
            'anniversary_date' => 'nullable|date',

            'default_currency' => 'nullable|string|max:3',
            'default_language' => 'nullable|string|max:40',
            'active'           => 'nullable|boolean',

            'group_ids'        => 'nullable|array',
            'group_ids.*'      => 'integer',
            'custom_fields'    => 'nullable|array',

            'contacts'                => 'nullable|array',
            'contacts.*.id'           => 'nullable|integer',
            'contacts.*.first_name'   => 'required_with:contacts|string|max:255',
            'contacts.*.last_name'    => 'nullable|string|max:255',
            'contacts.*.email'        => 'nullable|email|max:255',
            'contacts.*.phone'        => 'nullable|string|max:30',
            'contacts.*.title'        => 'nullable|string|max:100',
            'contacts.*.is_primary'   => 'nullable|boolean',
        ];
    }
}
