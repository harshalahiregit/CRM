<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'long_description' => 'nullable|string',
            'rate'             => 'sometimes|numeric|min:0',
            'unit'             => 'nullable|string|max:50',
            'tax_rate'         => 'nullable|numeric|min:0|max:100',
            'tax_rate_2'       => 'nullable|numeric|min:0|max:100',
            'category'         => 'nullable|string|max:100',
        ];
    }
}
