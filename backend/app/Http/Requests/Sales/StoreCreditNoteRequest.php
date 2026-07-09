<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'  => 'nullable|integer',
            'invoice_id' => 'nullable|exists:sales_invoices,id',
            'date'       => 'required|date',
            'currency'   => 'nullable|string|size:3',
            'reason'     => 'nullable|string',
            'adminnote'  => 'nullable|string',
            'clientnote' => 'nullable|string',
            'terms'      => 'nullable|string',
            'line_items' => 'nullable|array',
        ];
    }
}
