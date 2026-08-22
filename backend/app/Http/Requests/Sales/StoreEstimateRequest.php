<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'       => 'required|string|max:255',
            'client_id'     => 'nullable|integer',
            'project_id'    => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('projects', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'date'          => 'required|date',
            'valid_until'   => 'nullable|date',
            'currency'      => 'nullable|string|size:3',
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'estimate_type' => 'nullable|in:estimate,proforma',
            'discount_mode' => 'nullable|in:fixed,percent',
            'discount_value'=> 'nullable|numeric|min:0',
            'sale_agent'    => 'nullable|exists:users,id',
            'status'        => 'nullable|in:Draft,Sent,Accepted,Declined,Expired',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string',
            'state'         => 'nullable|string',
            'country'       => 'nullable|string',
            'zip'           => 'nullable|string',
            'adminnote'     => 'nullable|string',
            'clientnote'    => 'nullable|string',
            'terms'         => 'nullable|string',
            'tags'          => 'nullable|string',
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
