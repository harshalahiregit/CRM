<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id'       => 'nullable|exists:sales_invoices,id',
            'client_id'        => 'nullable|integer',
            'delivery_date'    => 'required|date',
            'shipping_address' => 'nullable|string',
            'shipping_city'    => 'nullable|string',
            'shipping_state'   => 'nullable|string',
            'shipping_country' => 'nullable|string',
            'shipping_zip'     => 'nullable|string',
            'note'             => 'nullable|string',
        ];
    }
}
