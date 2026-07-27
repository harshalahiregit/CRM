<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name'       => 'sometimes|required|string|max:150',
            'gender'          => 'nullable|string|max:20',
            'dob'             => 'nullable|date',
            'phone'           => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
            'designation'     => 'nullable|string|max:120',
            'id_proof_type'   => 'nullable|string|max:60',
            'id_proof_number' => 'nullable|string|max:80',
            'address'         => 'nullable|string|max:255',
            'city'            => 'nullable|string|max:120',
            'state'           => 'nullable|string|max:120',
            'pincode'         => 'nullable|string|max:20',
            'status'          => 'nullable|in:Active,Inactive,Pending',
            'notes'           => 'nullable|string|max:2000',
        ];
    }
}
