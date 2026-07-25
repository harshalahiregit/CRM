<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                  => 'sometimes|required|string',
            'department'             => 'nullable|string',
            'vendor_id'              => 'nullable|integer|exists:vendors,id',
            'order_date'             => 'nullable|date',
            'expected_delivery_date' => 'nullable|date',
            'currency'               => 'nullable|string|max:8',
            'terms'                  => 'nullable|string',
            'notes'                  => 'nullable|string',

            'items'                   => 'sometimes|array|min:1',
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
