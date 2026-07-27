<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => 'sometimes|required|string|max:200',
            'department'  => 'nullable|string|max:120',
            'required_by' => 'nullable|date',
            'closes_at'   => 'nullable|date',
            'currency'    => 'nullable|string|max:8',
            'notes'       => 'nullable|string|max:2000',

            'items'               => 'sometimes|array|min:1',
            'items.*.catalog_item_id' => 'nullable|integer',
            'items.*.description' => 'required_without:items.*.catalog_item_id|string|max:400',
            'items.*.qty'         => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string|max:40',
            'items.*.target_rate' => 'nullable|numeric|min:0',
            'items.*.tax'         => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'  => 'nullable|integer|min:0',

            'vendor_ids'   => 'sometimes|array',
            'vendor_ids.*' => 'integer',
        ];
    }
}
