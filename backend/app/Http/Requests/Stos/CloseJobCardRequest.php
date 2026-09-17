<?php

namespace App\Http\Requests\Stos;

use Illuminate\Foundation\Http\FormRequest;

class CloseJobCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'diagnosis'   => 'nullable|string|max:5000',
            'parts_cost'  => 'nullable|numeric|min:0|max:99999999',
            'labour_cost' => 'nullable|numeric|min:0|max:99999999',
            // Honoured over parts+labour when present: a signed card may carry a
            // discount or a warranty credit.
            'total_cost'  => 'nullable|numeric|min:0|max:99999999',
            'qc_passed'   => 'nullable|boolean',
        ];
    }
}
