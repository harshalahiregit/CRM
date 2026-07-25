<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchaseContractType;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'         => 'required|string|max:200',
            'type'          => 'required|string|in:'.implode(',', PurchaseContractType::ALL),
            'vendor_id'     => 'required|integer',
            'currency'      => 'nullable|string|max:8',
            'start_date'    => 'nullable|date',
            'end_date'      => 'nullable|date',
            'spend_ceiling' => 'nullable|numeric|min:0',
            'terms'         => 'nullable|string|max:5000',
            'notes'         => 'nullable|string|max:2000',

            // Rate lines — required for a rate contract at activation (service),
            // optional here so a draft can be built incrementally.
            'items'               => 'nullable|array',
            'items.*.description' => 'required|string|max:400',
            'items.*.unit'        => 'nullable|string|max:40',
            'items.*.rate'        => 'required|numeric|min:0',
            'items.*.tax'         => 'nullable|numeric|min:0|max:100',
            'items.*.min_qty'     => 'nullable|numeric|min:0',
            'items.*.max_qty'     => 'nullable|numeric|min:0',
            'items.*.sort_order'  => 'nullable|integer|min:0',
        ];
    }
}
