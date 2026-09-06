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
            // 'doctor' is a real User with its own portal (routes/medical.php),
            // so it belongs on this list. It was missing, which meant a doctor
            // login could be created but never used: the selector had no entry
            // and this rule would have refused the value anyway.
            'role'     => 'required|in:admin,staff,doctor,third_party_vendor,client,company',
            'remember' => 'nullable|boolean',
        ];
    }
}
