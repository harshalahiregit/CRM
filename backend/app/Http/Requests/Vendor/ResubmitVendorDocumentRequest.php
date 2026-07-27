<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class ResubmitVendorDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // No `type` — resubmit replaces the file on an existing document, whose
        // type is fixed.
        return [
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:8192',
        ];
    }
}
