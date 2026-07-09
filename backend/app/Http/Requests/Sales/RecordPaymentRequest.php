<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Route-model binding for the `{invoice}` route parameter (typed
        // `SalesInvoice $invoice` in the controller) resolves via the
        // SubstituteBindings middleware, which runs before the controller
        // method (and therefore before this FormRequest) is resolved from
        // the container. So $this->route('invoice') is already the bound
        // SalesInvoice model here, and its `balance` reflects the current
        // outstanding balance — same value the original inline
        // `max:' . $invoice->balance` rule used.
        $invoice = $this->route('invoice');
        $max = $invoice ? $invoice->balance : null;

        return [
            'amount'         => ['required', 'numeric', 'min:0.01', $max !== null ? "max:{$max}" : 'max:0'],
            'date'           => 'required|date',
            'mode'           => 'required|string',
            'transaction_id' => 'nullable|string|max:255',
            'note'           => 'nullable|string',
        ];
    }
}
