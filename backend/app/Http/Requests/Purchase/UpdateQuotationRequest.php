<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency'    => 'nullable|string|max:8',
            'valid_until' => 'nullable|date',
            'notes'       => 'nullable|string|max:2000',

            'items'                        => 'sometimes|array|min:1',
            'items.*.purchase_rfq_item_id' => 'nullable|integer',
            'items.*.description'          => 'required|string|max:400',
            'items.*.qty'                  => 'required|numeric|min:0.01',
            'items.*.unit'                 => 'nullable|string|max:40',
            'items.*.rate'                 => 'required|numeric|min:0',
            'items.*.tax'                  => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'           => 'nullable|integer|min:0',
        ];
    }
}
