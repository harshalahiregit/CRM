<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'     => 'sometimes|string|max:255',
            'status'      => 'sometimes|in:Draft,Sent,Accepted,Declined,Expired',
            'valid_until' => 'nullable|date',
            'line_items'  => 'nullable|array',
        ];
    }
}
