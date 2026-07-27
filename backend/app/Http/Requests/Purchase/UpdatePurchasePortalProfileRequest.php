<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Purchase Vendor Portal — self-service profile edit. Deliberately allows ONLY the
 * business fields a vendor may change. Vendor code, category, portal status and all
 * authentication fields (email/password/status) are excluded by omission — they can
 * never be mass-assigned from this request.
 */
class UpdatePurchasePortalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'  => 'sometimes|required|string|max:200',
            'legal_name'    => 'nullable|string|max:200',
            'gst_number'    => 'nullable|string|max:20',
            'pan_number'    => 'nullable|string|max:20',
            'phone'         => 'nullable|string|max:30',
            'website'       => 'nullable|string|max:200',
            'address'       => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:120',
            'state'         => 'nullable|string|max:120',
            'country'       => 'nullable|string|max:120',
            'pincode'       => 'nullable|string|max:20',
            'bank_details'  => 'nullable|string|max:2000',
            'payment_terms' => 'nullable|string|max:2000',
            'return_policy' => 'nullable|string|max:2000',
        ];
    }
}
