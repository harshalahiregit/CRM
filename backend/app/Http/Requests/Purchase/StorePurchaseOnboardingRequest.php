<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Tenant ownership is enforced in the service.
            'purchase_vendor_id' => 'required|integer|exists:purchase_vendors,id',
        ];
    }
}
