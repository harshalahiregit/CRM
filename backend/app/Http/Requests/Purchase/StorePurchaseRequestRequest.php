<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'         => 'required|string',
            'department'    => 'nullable|string',
            // Tenant ownership + Active status are enforced in the service, not here.
            'purchase_vendor_id'     => 'nullable|integer|exists:purchase_vendors,id',
            'required_by'   => 'nullable|date',
            'priority'      => 'nullable|in:Low,Normal,High,Urgent',
            'justification' => 'nullable|string',
            'currency'      => 'nullable|string|max:8',
            'remarks'       => 'nullable|string',

            'items'                   => 'required|array|min:1',
            'items.*.catalog_item_id' => 'nullable|integer',
            'items.*.description'     => 'required_without:items.*.catalog_item_id|string',
            'items.*.qty'             => 'required|numeric|min:0.01',
            'items.*.unit'            => 'nullable|string',
            'items.*.rate'            => 'nullable|numeric|min:0',
            'items.*.tax'             => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'      => 'nullable|integer|min:0',
        ];
    }
}
