<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeadStatusSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'sometimes|string|max:100',
            'color'         => 'nullable|string|max:9',
            'sort_order'    => 'nullable|integer',
            'is_default'    => 'nullable|boolean',
            'is_won_status' => 'nullable|boolean',
        ];
    }
}
