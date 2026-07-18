<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'               => 'required|string|max:200',
            'department'          => 'nullable|string|max:120',
            'purchase_request_id' => 'nullable|integer|exists:purchase_requests,id',
            'required_by'         => 'nullable|date',
            'closes_at'           => 'nullable|date',
            'currency'            => 'nullable|string|max:8',
            'notes'               => 'nullable|string|max:2000',

            'items'               => 'required|array|min:1',
            'items.*.catalog_item_id' => 'nullable|integer',
            // A catalog-picked line snapshots its description server-side, so it's
            // only required when the line is free-text (no catalog item chosen).
            'items.*.description' => 'required_without:items.*.catalog_item_id|string|max:400',
            'items.*.qty'         => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string|max:40',
            'items.*.target_rate' => 'nullable|numeric|min:0',
            'items.*.tax'         => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'  => 'nullable|integer|min:0',

            // Recipient vendors — validated as engageable in-tenant by the service.
            'vendor_ids'          => 'nullable|array',
            'vendor_ids.*'        => 'integer',
        ];
    }
}
