<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                => 'required|string|max:255',
            'color'               => 'nullable|string|max:20',
            'sort_order'          => 'nullable|integer',
            'is_default'          => 'nullable|boolean',
            'is_completed_status' => 'nullable|boolean',
        ];
    }
}
