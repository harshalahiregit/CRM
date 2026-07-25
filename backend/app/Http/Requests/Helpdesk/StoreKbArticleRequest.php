<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Foundation\Http\FormRequest;

class StoreKbArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subcategory_id' => 'required|integer|exists:kb_subcategories,id',
            'department_id'  => 'nullable|integer|exists:ticket_departments,id',
            'title'          => 'required|string|max:255',
            'excerpt'        => 'nullable|string|max:500',
            'content'        => 'required|string',   // WYSIWYG HTML (sanitized in service)
            'tags'           => 'nullable|array|max:12',
            'tags.*'         => 'string|max:40',
        ];
    }
}
