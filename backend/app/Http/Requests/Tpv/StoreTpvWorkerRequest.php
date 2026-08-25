<?php

namespace App\Http\Requests\Tpv;

use Illuminate\Foundation\Http\FormRequest;

class StoreTpvWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'         => 'nullable|integer',
            'work_package_id'   => 'nullable|integer',
            'name'              => 'required|string|max:120',
            'dob'               => 'nullable|date',
            'gender'            => 'nullable|string',
            'designation'       => 'nullable|string|max:120',
            'skill_category'    => 'nullable|string',
            'experience_years'  => 'nullable|numeric|min:0|max:80',
            'joining_date'      => 'nullable|date',
            'exit_date'         => 'nullable|date|after_or_equal:joining_date',
            'aadhar_number'     => 'nullable|string',
            'mobile'            => 'nullable|string|max:20',
            'blood_group'       => 'nullable|string|max:8',
            'address'           => 'nullable|string',
            'emergency_contact' => 'nullable|string|max:120',
            'emergency_phone'   => 'nullable|string|max:20',
            'remarks'           => 'nullable|string',
            'age_reason'        => 'nullable|string',
            'email'             => 'nullable|string',
        ];
    }
}
