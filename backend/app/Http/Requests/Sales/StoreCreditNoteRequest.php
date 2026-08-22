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
        ];
    }
}
