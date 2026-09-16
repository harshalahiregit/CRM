<?php

namespace App\Http\Requests\Stos;

use Illuminate\Foundation\Http\FormRequest;

class StoreFuelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'litres'         => 'required|numeric|gt:0|max:2000',
            'rate_per_litre' => 'required|numeric|gt:0|max:10000',
            'amount'         => 'required|numeric|gt:0|max:9999999',
            'odometer'       => 'nullable|numeric|min:0|max:9999999',
            'station_vendor' => 'nullable|string|max:150',
            'trip_id'        => 'nullable|integer|min:1',

            'is_emergency'         => 'nullable|boolean',
            'emergency_reason'     => 'nullable|string|max:255|required_if:is_emergency,true,1',
            'customer_recoverable' => 'nullable|boolean',

            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:'.(int) config('stos.fuel.receipt_max_kb', 8192),
        ];
    }

    public function messages(): array
    {
        return [
            'emergency_reason.required_if' => 'An emergency fill needs a reason — somebody has to answer for it.',
        ];
    }
}
