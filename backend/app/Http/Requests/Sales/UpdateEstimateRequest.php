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
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'discount_mode' => 'nullable|in:fixed,percent',
            'discount_value'=> 'nullable|numeric|min:0',
            'subject'     => 'sometimes|string|max:255',
            'status'      => 'sometimes|in:Draft,Sent,Accepted,Declined,Expired',
            'valid_until' => 'nullable|date',
            'line_items'  => 'nullable|array',
        ];
    }
}
