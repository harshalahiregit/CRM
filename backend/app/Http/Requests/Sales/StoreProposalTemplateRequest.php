<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreProposalTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'description'   => 'nullable|string',
            'category'      => 'nullable|string|max:100',
            'content'       => 'nullable|string',
            'thumbnail_url' => 'nullable|string|max:500',
            'is_default'    => 'nullable|boolean',
            'sort_order'    => 'nullable|integer',
        ];
    }
}
