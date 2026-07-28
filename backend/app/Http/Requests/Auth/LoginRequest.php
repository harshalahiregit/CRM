<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => 'required|email',
            'password' => 'required|string',
            // 'vendor' is deliberately absent. A Purchase Vendor is a PurchaseVendor
            // identity, not a User: it authenticates at /api/purchase-vendor/login and
            // holds a token whose tokenable is purchase_vendors. Issuing a User token
            // here could never satisfy EnsurePurchaseVendorPortalAccess, so this door
            // is closed server-side and not merely hidden in the role selector.
            'role'     => 'required|in:admin,staff,third_party_vendor,client,company',
            'remember' => 'nullable|boolean',
        ];
    }
}
