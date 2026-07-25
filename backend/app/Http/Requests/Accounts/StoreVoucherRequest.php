<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Any of this tenant's active voucher types — the nine seeded kinds
            // plus any custom types the tenant has added.
            'voucher_type_code' => ['required', 'string', Rule::exists('acc_voucher_types', 'code')
                ->where('tenant_id', $this->user()->tenant_id)
                ->where('active', true)],
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
