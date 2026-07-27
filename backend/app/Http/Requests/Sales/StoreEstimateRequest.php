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
        ];
    }
}
