<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreChequebookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer'],
            'name'            => ['required', 'string', 'max:120'],
            'prefix'          => ['nullable', 'string', 'max:20'],
            // Accept the raw strings so leading zeros ("000001") set the pad width,
            // and the numeric values for the range maths.
            'start_no'        => ['required', 'integer', 'min:0'],
            'end_no'          => ['required', 'integer', 'min:0'],
            'start_raw'       => ['nullable', 'string', 'max:20'],
            'end_raw'         => ['nullable', 'string', 'max:20'],
            'notes'           => ['nullable', 'string', 'max:1000'],
        ];
    }
}
