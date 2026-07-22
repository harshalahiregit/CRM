<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id'             => ['sometimes', 'required', 'integer'],
            'name'                 => ['sometimes', 'required', 'string', 'max:255'],
            'code'                 => ['nullable', 'string', 'max:50'],
            'opening_balance'      => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['nullable', 'in:dr,cr'],
            'is_bank'              => ['nullable', 'boolean'],
            'is_cash'              => ['nullable', 'boolean'],
            'is_party'             => ['nullable', 'boolean'],
            'party_id'             => ['nullable', 'integer'],
            'is_active'            => ['nullable', 'boolean'],
        ];
    }
}
