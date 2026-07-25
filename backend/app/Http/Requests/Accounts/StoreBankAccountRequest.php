<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:255'],
            'account_no'           => ['nullable', 'string', 'max:40'],
            'ifsc'                 => ['nullable', 'string', 'max:20'],
            'bank_name'            => ['nullable', 'string', 'max:255'],
            'branch'               => ['nullable', 'string', 'max:255'],
            'account_type'         => ['nullable', 'in:savings,current,od,cc,other'],
            'opening_balance'      => ['nullable', 'numeric'],
            'opening_balance_type' => ['nullable', 'in:dr,cr'],
        ];
    }
}
