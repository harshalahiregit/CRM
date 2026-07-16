<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'             => 'sometimes|string|max:255',
            'contract_type_id'    => 'nullable|exists:contract_types,id',
            'value'               => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|size:3',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date',
            'description'         => 'nullable|string',
            'renewal_notice_days' => 'nullable|integer|min:0',
        ];
    }
}
