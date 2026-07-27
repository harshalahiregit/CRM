<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseOrderReturnStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            // Purchase-owned vendor master only.
            'purchase_vendor_id' => [
                'required', 'integer',
                Rule::exists('purchase_vendors', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'purchase_order_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_orders', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'return_date'      => 'nullable|date',
            'reason'           => 'nullable|string|max:2000',
            'adjust_inventory' => 'boolean',
            'currency'         => 'nullable|string|max:8',
            'notes'            => 'nullable|string|max:5000',
            'status'           => ['nullable', Rule::in(PurchaseOrderReturnStatus::ALL)],

            'items'                          => 'required|array|min:1',
            'items.*.purchase_order_item_id'  => 'nullable|integer',
            'items.*.description'             => 'required|string|max:255',
            'items.*.qty'                     => 'required|numeric|min:0.001',
            'items.*.unit'                    => 'nullable|string|max:30',
            'items.*.rate'                    => 'required|numeric|min:0',
            'items.*.discount'                => 'nullable|numeric|min:0',
            'items.*.tax'                     => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_vendor_id.required' => 'Please select a Purchase Vendor.',
            'items.required'              => 'Add at least one returned line.',
        ];
    }
}
