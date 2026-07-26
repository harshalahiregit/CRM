<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebToLeadFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'form_data'         => 'nullable|array',
            'form_data.*.key'   => 'required_with:form_data|string',
            'form_data.*.label' => 'required_with:form_data|string',
            'form_data.*.type'  => 'nullable|string',
            'form_data.*.required' => 'nullable|boolean',
            'lead_source_id'    => 'nullable|exists:lead_sources,id',
            'lead_status_id'    => 'nullable|exists:lead_statuses,id',
            'assigned_to'       => 'nullable|exists:users,id',
            'success_message'   => 'nullable|string|max:500',
            'redirect_url'      => 'nullable|string|max:500',
            'allow_duplicate'   => 'nullable|boolean',
            'recaptcha_enabled' => 'nullable|boolean',
            'is_active'         => 'nullable|boolean',
        ];
    }
}
