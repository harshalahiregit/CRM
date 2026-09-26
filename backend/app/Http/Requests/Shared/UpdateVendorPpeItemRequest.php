<?php

namespace App\Http\Requests\Shared;

use App\Support\Shared\VendorPpeCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edit an item on a vendor's own PPE list, including a stock correction. */
class UpdateVendorPpeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'sometimes|required|string|max:160',
            'category'     => ['sometimes', 'required', 'string', Rule::in(VendorPpeCategory::keys())],
            'size'         => 'nullable|string|max:40',
            'spec'         => 'nullable|string|max:255',
            'unit'         => 'nullable|string|max:20',
            'qty_in_stock' => 'sometimes|required|numeric|min:0|max:9999999',
            'is_active'    => 'nullable|boolean',
            'notes'        => 'nullable|string|max:2000',
            'image'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }
}
