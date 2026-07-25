<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'         => 'nullable|integer|exists:vendors,id',
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
            'debit_date'        => 'nullable|date',
            'reason'            => 'nullable|string',
            'adjust_inventory'  => 'nullable|boolean',
            'currency'          => 'nullable|string|max:8',
            'notes'             => 'nullable|string',

            'items'                          => 'required|array|min:1',
            'items.*.description'            => 'required|string',
            'items.*.purchase_order_item_id' => 'nullable|integer',
            'items.*.qty'                    => 'required|numeric|min:0.01',
            'items.*.unit'                   => 'nullable|string',
            'items.*.rate'                   => 'required|numeric|min:0',
            'items.*.tax'                    => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'             => 'nullable|integer|min:0',
        ];
    }
}
