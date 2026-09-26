<?php

namespace App\Http\Requests\Shared;

use App\Support\Shared\VendorPpeCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new item on a vendor's own PPE list — TPV and Purchase portals alike.
 *
 * No vendor id is accepted: the owner is always the vendor behind the token.
 */
class StoreVendorPpeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The portal middleware has already established who the vendor is; the
        // controller forces ownership from it.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:160',
            'category'     => ['required', 'string', Rule::in(VendorPpeCategory::keys())],
            'size'         => 'nullable|string|max:40',
            'spec'         => 'nullable|string|max:255',
            'unit'         => 'nullable|string|max:20',
            'qty_in_stock' => 'required|numeric|min:0|max:9999999',
            'is_active'    => 'nullable|boolean',
            'notes'        => 'nullable|string|max:2000',
            'image'        => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ];
    }
}
