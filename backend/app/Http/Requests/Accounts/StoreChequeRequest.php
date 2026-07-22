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
            'direction'       => ['required', 'in:issued,received'],
            'bank_account_id' => ['nullable', 'integer'],
            'voucher_id'      => ['nullable', 'integer'],
            'cheque_no'       => ['nullable', 'string', 'max:40'],
            'cheque_date'     => ['required', 'date'],
            'party_name'      => ['nullable', 'string', 'max:255'],
            'amount'          => ['required', 'numeric', 'min:0'],
            'is_pdc'          => ['nullable', 'boolean'],
            'pdc_due_date'    => ['nullable', 'date'],
            'memo'            => ['nullable', 'string', 'max:255'],
        ];
    }
}
