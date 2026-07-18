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
            // Tenant ownership is enforced in the service.
            'vendor_id'         => 'required|integer|exists:vendors,id',
            'name'              => 'required|string|max:120',
            // The 18+ floor is a badge gate (not a create gate) so a part-filled
            // draft can still be saved; `before:today` only rejects nonsense.
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
