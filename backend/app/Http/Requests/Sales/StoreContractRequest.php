<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'             => 'required|string|max:255',
            'client_id'           => 'required|exists:clients,id',
            'contract_type_id'    => 'nullable|exists:contract_types,id',
            'value'               => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|size:3',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date',
            'description'         => 'nullable|string',
            'status'              => 'nullable|in:draft,active,expired,terminated,renewed',
            'renewal_notice_days' => 'nullable|integer|min:0',
            'pages'            => 'nullable|array',
            'pages.*.title'    => 'nullable|string|max:255',
            'pages.*.content'  => 'nullable|string',
        ];
    }
}
