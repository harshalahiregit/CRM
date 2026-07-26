<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_id'     => 'required|exists:hr_candidates,id',
            'position'         => 'required|string',
            'department'       => 'required|string',
            'offered_ctc'      => 'required|numeric|min:0',
            'salary_structure_id' => 'nullable|integer|exists:hr_salary_structures,id',
            'joining_date'     => 'required|date',
            'probation_period' => 'nullable|string',
            'notice_period'    => 'nullable|string',
            'validity_date'    => 'nullable|date',
        ];
    }
}
