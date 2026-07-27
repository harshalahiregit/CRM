<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction'        => ['required', 'in:issued,received'],
            'bank_account_id'  => ['nullable', 'integer'],
            'chequebook_id'    => ['nullable', 'integer'],
            'voucher_id'       => ['nullable', 'integer'],
            'cheque_no'        => ['nullable', 'string', 'max:40'],
            'cheque_date'      => ['required', 'date'],
            'party_name'       => ['nullable', 'string', 'max:255'],
            // Structured payee/payer link (seam for the future Vendor / TPV / Customer directory).
            'party_type'       => ['nullable', 'in:customer,vendor,tpv'],
            'party_id'         => ['nullable', 'integer'],
            'project_id'       => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('projects', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'amount'           => ['required', 'numeric', 'min:0'],
            'is_account_payee' => ['nullable', 'boolean'],
            'is_pdc'           => ['nullable', 'boolean'],
            'pdc_due_date'     => ['nullable', 'date'],
            'memo'             => ['nullable', 'string', 'max:255'],
            'reference'        => ['nullable', 'string', 'max:255'],
            'source_type'      => ['nullable', 'in:client,vendor,other'],
            'payer_bank'       => ['nullable', 'string', 'max:255'],
            // Received cheques may be recorded straight into a deposit state.
            'status'           => ['nullable', 'in:issued,received,deposited,presented,cleared,bounced,cancelled,post_dated'],
        ];
    }
}
