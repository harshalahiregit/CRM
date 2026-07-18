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
            'vendor_id'         => 'sometimes|required|integer|exists:vendors,id',
            'name'              => 'sometimes|required|string|max:120',
            'dob'               => 'nullable|date|before:today',
            'gender'            => 'nullable|in:Male,Female,Other,Prefer not to say',
            'designation'       => 'nullable|string|max:120',
            'skill_category'    => 'nullable|in:Skilled,Semi_Skilled,Unskilled,Supervisor',
            'aadhar_number'     => 'nullable|digits:12',
            'mobile'            => 'nullable|string|max:20',
            'blood_group'       => 'nullable|string|max:8',
            'address'           => 'nullable|string',
            'emergency_contact' => 'nullable|string|max:120',
            'emergency_phone'   => 'nullable|string|max:20',
            'remarks'           => 'nullable|string',
        ];
    }
}
