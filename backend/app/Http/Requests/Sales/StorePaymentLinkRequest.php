<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id'      => 'nullable|exists:sales_invoices,id',
            'amount'          => 'required|numeric|min:0.01',
            'currency'        => 'nullable|string|size:3',
            'expiry_date'     => 'nullable|date|after:now',
            'client_email'    => 'nullable|email',
            'client_name'     => 'nullable|string|max:255',
            'description'     => 'nullable|string',
            'payment_gateway' => 'nullable|string|max:100',
        ];
    }
}
