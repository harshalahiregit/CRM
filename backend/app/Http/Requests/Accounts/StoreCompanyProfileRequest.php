<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legal_name'       => ['required', 'string', 'max:255'],
            'trade_name'       => ['nullable', 'string', 'max:255'],
            'pan'              => ['nullable', 'string', 'max:10'],
            'gstin'            => ['nullable', 'string', 'max:15'],
            'tan'              => ['nullable', 'string', 'max:10'],
            'state_code'       => ['nullable', 'string', 'max:2'],
            'entity_type'      => ['nullable', 'in:company,llp,partnership,proprietorship,trust'],
            'books_begin_date' => ['nullable', 'date'],
            'base_currency'    => ['nullable', 'string', 'max:3'],
        ];
    }
}
