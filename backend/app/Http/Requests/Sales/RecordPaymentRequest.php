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
            'tds_amount'     => 'nullable|numeric|min:0',
            'tds_section'    => 'nullable|string|max:20',
            'tds_percentage' => 'nullable|numeric|min:0|max:100',
            'payment_type'   => 'nullable|in:received,paid',
        ];
    }

    /**
     * TDS settles the invoice alongside the cash, so the two together are what
     * must fit inside the outstanding balance. The `max` on `amount` alone would
     * happily accept ₹1,00,000 cash plus ₹2,000 TDS against a ₹1,00,000 balance
     * and over-settle it by the TDS.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $invoice = $this->route('invoice');
            if (! $invoice) {
                return;
            }

            $settles = (float) $this->input('amount', 0) + (float) $this->input('tds_amount', 0);

            // Half a paisa of tolerance: a percentage-derived TDS is rounded in
            // the browser, and a rounding crumb must not reject a correct entry.
            if ($settles > (float) $invoice->balance + 0.005) {
                $validator->errors()->add(
                    'amount',
                    'The payment and TDS together exceed the outstanding balance.'
                );
            }
        });
    }
}
