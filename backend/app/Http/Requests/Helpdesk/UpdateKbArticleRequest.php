<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKbArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subcategory_id' => 'sometimes|integer|exists:kb_subcategories,id',
            'title'          => 'sometimes|required|string|max:255',
            'excerpt'        => 'nullable|string|max:500',
            'content'        => 'sometimes|required|string',
        ];
    }
}
