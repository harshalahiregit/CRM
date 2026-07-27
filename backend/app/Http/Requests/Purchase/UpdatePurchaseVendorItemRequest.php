<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseVendorItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseVendorItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'purchase_vendor_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('purchase_vendors', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'inventory_product_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('inventory_products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'effective_date' => 'nullable|date',
            'remarks'        => 'nullable|string|max:2000',
            'status'         => ['nullable', Rule::in(PurchaseVendorItemStatus::ALL)],
        ];
    }
}
