<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'map_address' => ['nullable', 'string', 'max:500'],
            'latitude'    => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required_with'  => 'A pin needs both a latitude and a longitude.',
            'longitude.required_with' => 'A pin needs both a latitude and a longitude.',
        ];
    }
}
