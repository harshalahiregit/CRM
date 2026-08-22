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
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'discount_mode' => 'nullable|in:fixed,percent',
            'discount_value'=> 'nullable|numeric|min:0',
            'status'             => 'sometimes|in:Draft,Unpaid,Partially Paid,Paid,Overdue,Cancelled',
            'due_date'           => 'sometimes|date',
            'adminnote'          => 'nullable|string',
            'clientnote'         => 'nullable|string',
            'terms'              => 'nullable|string',
            'line_items'    => 'nullable|array',
            // Line rules were absent entirely, so a negative qty or rate
            // persisted and produced a negative-total document that the ledger
            // then had to carry. Mirrors StoreProposalRequest, which had them.
            'line_items.*.item_name'    => 'required_with:line_items|string',
            'line_items.*.qty'          => 'required_with:line_items|numeric|min:0',
            'line_items.*.rate'         => 'required_with:line_items|numeric|min:0',
            'line_items.*.tax'          => 'nullable|numeric|min:0|max:100',
            'line_items.*.taxes'        => 'nullable|array',
            'line_items.*.taxes.*.name' => 'required_with:line_items.*.taxes|string|max:60',
            'line_items.*.taxes.*.rate' => 'required_with:line_items.*.taxes|numeric|min:0|max:100',
            'line_items.*.discount'     => 'nullable|numeric|min:0',
            'line_items.*.discount_mode' => 'nullable|in:fixed,percent',
            'line_items.*.unit'         => 'nullable|string',
            'line_items.*.description'  => 'nullable|string',
            'line_items.*.hsn_sac_code' => 'nullable|string|max:20',
            'gst_paid'           => 'sometimes|boolean',
            'msme_udyam_number'  => 'nullable|string|max:50',
            'eway_bill_number'   => 'nullable|string|max:20',
            'eway_bill_date'     => 'nullable|date',
        ];
    }
}
