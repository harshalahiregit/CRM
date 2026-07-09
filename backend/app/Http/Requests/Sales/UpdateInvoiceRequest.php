<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'             => 'sometimes|in:Draft,Unpaid,Partially Paid,Paid,Overdue,Cancelled',
            'due_date'           => 'sometimes|date',
            'adminnote'          => 'nullable|string',
            'clientnote'         => 'nullable|string',
            'terms'              => 'nullable|string',
            'line_items'         => 'nullable|array',
            'gst_paid'           => 'sometimes|boolean',
            'msme_udyam_number'  => 'nullable|string|max:50',
            'eway_bill_number'   => 'nullable|string|max:20',
            'eway_bill_date'     => 'nullable|date',
        ];
    }
}
