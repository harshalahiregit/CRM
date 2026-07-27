<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        // NOTE: or_number and status are intentionally absent — the number is
        // immutable and status moves only through issue/complete/cancel.
        return [
            'purchase_vendor_id' => [
                'sometimes', 'required', 'integer',
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

            'items'                          => 'sometimes|array|min:1',
            'items.*.purchase_order_item_id'  => 'nullable|integer',
            'items.*.description'             => 'required|string|max:255',
            'items.*.qty'                     => 'required|numeric|min:0.001',
            'items.*.unit'                    => 'nullable|string|max:30',
            'items.*.rate'                    => 'required|numeric|min:0',
            'items.*.discount'                => 'nullable|numeric|min:0',
            'items.*.tax'                     => 'nullable|numeric|min:0|max:100',
        ];
    }
}
