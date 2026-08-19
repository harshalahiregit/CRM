<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTpvWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'         => 'nullable|integer',
            'name'              => 'sometimes|required|string|max:120',
            'dob'               => 'nullable|date',
            'gender'            => 'nullable|string',
            'designation'       => 'nullable|string|max:120',
            'skill_category'    => 'nullable|string',
            'aadhar_number'     => 'nullable|string',
            'mobile'            => 'nullable|string|max:20',
            'blood_group'       => 'nullable|string|max:8',
            'address'           => 'nullable|string',
            'emergency_contact' => 'nullable|string|max:120',
            'emergency_phone'   => 'nullable|string|max:20',
            'remarks'           => 'nullable|string',
            'age_reason'        => 'nullable|string',
            'email'             => 'nullable|string',
            'awards'            => 'nullable|string|max:2000',
            'bocw_number'       => 'nullable|string|max:60',
        ];
    }
}
