<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadQuestionnaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'fields'      => 'required|array|min:1',
            'fields.*.label'       => 'required|string|max:255',
            'fields.*.field_type'  => 'required|in:text,textarea,number,email,phone,date,select,multi_select,checkbox,radio,file',
            'fields.*.options'     => 'nullable|array',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.is_required' => 'nullable|boolean',
        ];
    }
}
