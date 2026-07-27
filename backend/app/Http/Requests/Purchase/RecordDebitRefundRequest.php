<?php

namespace App\Http\Requests\Purchase;

use App\Support\Purchase\PurchasePaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordDebitRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Over-refund against the debit balance is enforced in the service.
            'amount'      => 'required|numeric|min:0.01',
            'refund_date' => 'nullable|date',
            'refund_mode' => ['nullable', Rule::in(PurchasePaymentMode::ALL)],
            'reference'   => 'nullable|string',
            'notes'       => 'nullable|string',
        ];
    }
}
