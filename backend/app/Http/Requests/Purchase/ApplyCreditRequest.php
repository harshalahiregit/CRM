<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Applying a debit note's credit to an invoice. The vendor-match, balance and
 * status rules are business logic (PurchaseCreditApplicationService) — this only
 * checks shape.
 */
class ApplyCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_invoice_id' => 'required|integer|exists:purchase_invoices,id',
            'amount'              => 'required|numeric|min:0.01',
            'applied_date'        => 'nullable|date',
            'reference'           => 'nullable|string|max:120',
            'notes'               => 'nullable|string|max:500',
        ];
    }
}
