<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A note against a vendor. The subject comes from the route, so notable_type and
 * notable_id are deliberately not accepted here.
 */
class StoreVendorNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'   => 'required|string|max:255',
            'content' => 'nullable|string',
        ];
    }
}
