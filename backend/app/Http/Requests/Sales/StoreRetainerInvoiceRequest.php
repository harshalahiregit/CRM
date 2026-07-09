<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreRetainerInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'             => 'nullable|integer',
            'amount'                => 'required|numeric|min:0.01',
            'currency'              => 'nullable|string|size:3',
            'billing_period_start'  => 'required|date',
            'billing_period_end'    => 'required|date|after:billing_period_start',
            'retainer_type'         => 'required|in:monthly,quarterly,yearly',
            'auto_create'           => 'nullable|boolean',
            'next_billing_date'     => 'nullable|date',
        ];
    }
}
