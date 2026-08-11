<?php

namespace App\Http\Requests\Sales;

use App\Models\Sales\SalesDocumentTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'doc_type'    => ['required', Rule::in(SalesDocumentTemplate::TYPES)],
            // Unique per tenant AND type — "Standard" may exist for both an
            // invoice and an estimate. Soft-deleted rows keep their slot, so they
            // are excluded or a re-used name would collide with a hidden record.
            'name'        => [
                'required', 'string', 'max:255',
                Rule::unique('sales_document_templates')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('doc_type', $this->input('doc_type'))
                        ->whereNull('deleted_at')),
            ],
            'description' => 'nullable|string|max:255',
            'terms'       => 'nullable|string',
            'adminnote'   => 'nullable|string',
            'clientnote'  => 'nullable|string',
            'currency'    => 'nullable|string|max:8',

            'discount_type'  => 'nullable|in:before_tax,after_tax',
            'discount_mode'  => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0',

            'sort_order'  => 'nullable|integer|min:0',

            'line_items'                 => 'nullable|array',
            'line_items.*.item_id'       => 'nullable|integer',
            'line_items.*.item_name'     => 'required_with:line_items|string|max:255',
            'line_items.*.description'   => 'nullable|string',
            'line_items.*.hsn_sac_code'  => 'nullable|string|max:20',
            'line_items.*.qty'           => 'nullable|numeric',
            'line_items.*.unit'          => 'nullable|string|max:20',
            'line_items.*.rate'          => 'nullable|numeric',
            'line_items.*.tax'           => 'nullable|numeric',
            'line_items.*.taxes'         => 'nullable',
            'line_items.*.discount'      => 'nullable|numeric',
            'line_items.*.discount_mode' => 'nullable|in:fixed,percent',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a template with this name for this document type.',
        ];
    }
}
