<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseVendorItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseVendorItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            // Vendor side — Purchase-owned master only.
            'purchase_vendor_id' => [
                'required', 'integer',
                Rule::exists('purchase_vendors', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            // Item side — the Inventory Item Master (never duplicated here).
            'inventory_product_id' => [
                'required', 'integer',
                Rule::exists('inventory_products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'effective_date' => 'nullable|date',
            'remarks'        => 'nullable|string|max:2000',
            'status'         => ['nullable', Rule::in(PurchaseVendorItemStatus::ALL)],
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_vendor_id.required'   => 'Please select a Purchase Vendor.',
            'inventory_product_id.required' => 'Please select an Inventory Item.',
        ];
    }
}
