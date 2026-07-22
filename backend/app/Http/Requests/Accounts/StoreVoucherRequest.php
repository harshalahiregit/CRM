<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation only — the balance invariant, ledger-tenant ownership,
 * and per-line dr/cr exclusivity are enforced in PostingService (the one gate),
 * so they hold no matter how a voucher is posted.
 */
class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'voucher_type_code' => ['required', 'string', 'in:sales,purchase,payment,receipt,contra,journal,debit_note,credit_note,stock_journal'],
            'date'              => ['required', 'date'],
            'narration'         => ['nullable', 'string', 'max:1000'],
            'party_id'          => ['nullable', 'integer'],
            'reference_no'      => ['nullable', 'string', 'max:100'],
            'lines'             => ['required', 'array', 'min:2'],
            'lines.*.ledger_id' => ['required', 'integer'],
            'lines.*.debit'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'    => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_narration' => ['nullable', 'string', 'max:255'],
        ];
    }
}
