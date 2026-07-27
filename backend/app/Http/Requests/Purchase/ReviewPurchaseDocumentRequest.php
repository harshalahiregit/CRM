<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|in:approve,reject',
            // Required-on-reject is enforced in the service.
            'remarks'  => 'nullable|string',
        ];
    }
}
