<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => 'required|string',
            'email'                  => 'nullable|email',
            'phone'                  => 'nullable|string',
            'dob'                    => 'nullable|date',
            'gender'                 => 'nullable|in:Male,Female,Other,Prefer not to say',
            'address'                => 'nullable|string',
            'department'             => 'required|string',
            'designation'            => 'required|string',
            'reporting_manager_name' => 'nullable|string',
            'joining_date'           => 'required|date',
            'probation_end_date'     => 'nullable|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'in:Active,On Leave,Inactive',
        ];
    }
}
