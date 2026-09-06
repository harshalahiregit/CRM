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
            // OPTIONAL, so the web takes exactly what the app takes: an email
            // and a password. It was required, and picking the wrong entry in
            // the dropdown failed a login whose credentials were perfectly
            // correct — the app has no such selector, so the same person could
            // sign in on their phone and not on the website.
            //
            // Safe to omit because users.email is globally unique: an address
            // resolves to exactly one account, so the role adds no precision. It
            // is still honoured when sent, which keeps every existing caller and
            // the role-scoped portal doors working unchanged.
            'role'     => 'nullable|in:admin,staff,third_party_vendor,client,company',
            'remember' => 'nullable|boolean',
        ];
    }
}
