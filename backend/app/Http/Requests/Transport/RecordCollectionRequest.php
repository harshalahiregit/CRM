<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording a receipt against a receivable — SNG-TRN-016, API-011, CTR-014.
 *
 * CTR-014 verbatim:
 *   amount_received | body | DECIMAL(18,2) | required | ">0" | tenant scope
 *                   | note: "Posting event generated"
 *
 * The field name is the registry's. `decimal:0,2` rather than plain `numeric`,
 * so 1200.999 is refused instead of being quietly rounded into money — and the
 * service re-checks with bccomp, because this layer is the only one an internal
 * caller can bypass.
 *
 * `reference` is not in the registry. It is accepted because a receipt somebody
 * cannot tie to a bank line is a receipt nobody can reconcile, and it is
 * optional because Transport is not the system of record for it — Accounts is.
 */
class RecordCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_received' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'reference'       => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_received.required' => 'An amount is required.',
            'amount_received.gt'       => 'A receipt must be greater than zero.',
            'amount_received.decimal'  => 'An amount may have at most two decimal places.',
        ];
    }
}
