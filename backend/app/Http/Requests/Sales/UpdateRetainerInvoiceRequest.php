<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRetainerInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'                => 'sometimes|numeric|min:0.01',
            'billing_period_start'  => 'sometimes|date',
            'billing_period_end'    => 'sometimes|date|after:billing_period_start',
            'status'                => 'sometimes|in:Draft,Sent,Paid,Overdue',
            'retainer_type'         => 'sometimes|in:monthly,quarterly,yearly',
            'auto_create'           => 'nullable|boolean',
            'next_billing_date'     => 'nullable|date',
        ];
    }
}
