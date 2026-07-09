<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeadGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_count'    => 'nullable|integer|min:0',
            'target_value'    => 'nullable|numeric|min:0',
            'incentive_type'  => 'nullable|in:none,fixed,percentage',
            'incentive_value' => 'nullable|numeric|min:0',
        ];
    }
}
