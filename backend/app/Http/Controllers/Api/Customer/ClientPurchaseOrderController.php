<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\ClientPurchaseOrder;
use Illuminate\Validation\Rule;

/**
 * §17 COMMERCIAL — the purchase orders a customer issues to us.
 *
 * `consumed` is not accepted from the caller. It is what has actually been
 * billed against the PO, which Sales owns; letting a form set it would make the
 * remaining-headroom figure a claim rather than a fact.
 */
class ClientPurchaseOrderController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'purchaseOrders';
    }

    protected function htmlFields(): array
    {
        return ['scope', 'notes'];
    }

    protected function rules(): array
    {
        return [
            'po_number'   => 'required|string|max:100',
            'po_date'     => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:po_date',
            'currency'    => 'nullable|string|max:8',
            'value'       => 'required|numeric|min:0',
            'status'      => ['nullable', Rule::in(ClientPurchaseOrder::STATUSES)],
            'contract_id' => 'nullable|integer',
            'scope'       => 'nullable|string',
            'notes'       => 'nullable|string',
        ];
    }
}
