<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'                 => 'sometimes|required|string|max:60',
            'name'                => 'sometimes|required|string|max:200',
            'category'            => 'nullable|string|max:120',
            'description'         => 'nullable|string|max:2000',
            'uom'                 => 'nullable|string|max:40',
            'default_rate'        => 'nullable|numeric|min:0',
            'default_tax'         => 'nullable|numeric|min:0|max:100',
            'hsn_code'            => 'nullable|string|max:40',
            'preferred_vendor_id' => 'nullable|integer',
            'notes'               => 'nullable|string|max:2000',
        ];
    }
}
