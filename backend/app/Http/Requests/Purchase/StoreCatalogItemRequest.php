<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseCatalogStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'                 => 'nullable|string|max:60',   // auto-generated if blank
            'name'                => 'required|string|max:200',
            'category'            => 'nullable|string|max:120',
            'description'         => 'nullable|string|max:2000',
            'uom'                 => 'nullable|string|max:40',
            'default_rate'        => 'nullable|numeric|min:0',
            'default_tax'         => 'nullable|numeric|min:0|max:100',
            'hsn_code'            => 'nullable|string|max:40',
            'preferred_purchase_vendor_id' => 'nullable|integer',
            'status'              => 'nullable|string|in:'.implode(',', PurchaseCatalogStatus::ALL),
            'notes'               => 'nullable|string|max:2000',
        ];
    }
}
