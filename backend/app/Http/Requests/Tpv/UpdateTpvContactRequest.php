<?php

namespace App\Http\Requests\Tpv;

use App\Support\Tpv\TpvContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTpvContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $phone = ['string', 'regex:/^[0-9+\-\s()]{7,15}$/'];

        return [
            'first_name'       => 'sometimes|required|string|max:100',
            'last_name'        => 'sometimes|required|string|max:100',
            'designation'      => 'sometimes|required|string|max:120',
            'department'       => 'nullable|string|max:120',
            'email'            => 'sometimes|required|email|max:150',
            'mobile'           => array_merge(['sometimes', 'required'], $phone),
            'alternate_mobile' => array_merge(['nullable'], $phone),
            'is_primary'       => 'boolean',
            'status'           => ['nullable', Rule::in(TpvContactStatus::ALL)],
        ];
    }
}
