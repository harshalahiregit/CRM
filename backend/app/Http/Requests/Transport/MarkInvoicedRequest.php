<?php

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accounts recording that they raised the invoice — STT-010, D-106.
 *
 * `invoice_id` is FLD-016's column and the only field this endpoint takes. No
 * amount, no date, no number: Transport is not the system of record for an
 * invoice and must not hold a second copy of its figures, which would then
 * disagree with the real one.
 *
 * Not validated against an invoices table, deliberately. Transport may run
 * without the Accounts module installed — the same reason `trip_bills` carries
 * no FK constraint on this column — so an `exists:` rule would fail in a
 * Transport-only deployment. The linkage is Accounts' to keep honest; refusing
 * a plausible id we cannot verify would be theatre.
 */
class MarkInvoicedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_id.required' => 'The invoice id is required.',
            'invoice_id.min'      => 'That is not a valid invoice id.',
        ];
    }
}
