<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'              => 'nullable|string',
            'vendor_id'          => 'nullable|integer|exists:vendors,id',
            'vendor_invoice_ref' => 'nullable|string',
            'invoice_date'       => 'nullable|date',
            'due_date'           => 'nullable|date',
            'currency'           => 'nullable|string|max:8',
            'terms'              => 'nullable|string',
            'notes'              => 'nullable|string',

            'items'               => 'sometimes|array|min:1',
            // Links the line to the PO line it bills, so 3-way match can
            // reconcile it. Nullable — a free-hand invoice line has none.
            'items.*.purchase_order_item_id' => 'nullable|integer',
            'items.*.description' => 'required|string',
            'items.*.qty'         => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string',
            'items.*.rate'        => 'required|numeric|min:0',
            'items.*.tax'         => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'  => 'nullable|integer|min:0',
        ];
    }
}
