<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff recording a vendor's quotation against an RFQ. The vendor must be on the
 * RFQ's recipient list (checked in the service); each line may link to the RFQ
 * line it answers so the comparison matrix lines up.
 */
class RecordQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'   => 'required|integer',
            'currency'    => 'nullable|string|max:8',
            'valid_until' => 'nullable|date',
            'notes'       => 'nullable|string|max:2000',

            'items'                        => 'required|array|min:1',
            'items.*.purchase_rfq_item_id' => 'nullable|integer',
            'items.*.description'          => 'required|string|max:400',
            'items.*.qty'                  => 'required|numeric|min:0.01',
            'items.*.unit'                 => 'nullable|string|max:40',
            'items.*.rate'                 => 'required|numeric|min:0',
            'items.*.tax'                  => 'nullable|numeric|min:0|max:100',
            'items.*.sort_order'           => 'nullable|integer|min:0',
        ];
    }
}
