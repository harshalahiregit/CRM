<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_date'     => 'nullable|date',
            'delivery_note_ref' => 'nullable|string',
            'notes'             => 'nullable|string',

            // description + ordered_qty are taken from the PO server-side; the
            // client only supplies which line and how much was accepted/rejected.
            'items'                          => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|integer',
            'items.*.accepted_qty'           => 'nullable|numeric|min:0',
            'items.*.rejected_qty'           => 'nullable|numeric|min:0',
            'items.*.remarks'                => 'nullable|string',
        ];
    }
}
