<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class StoreKbSubcategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|integer|exists:kb_categories,id',
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255',
        ];
    }
}
