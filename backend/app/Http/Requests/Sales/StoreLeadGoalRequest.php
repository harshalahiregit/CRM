<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'         => 'nullable|exists:users,id',
            'type'            => 'required|in:monthly,quarterly,yearly',
            'period_start'    => 'required|date',
            'period_end'      => 'required|date|after:period_start',
            'target_count'    => 'nullable|integer|min:0',
            'target_value'    => 'nullable|numeric|min:0',
            'incentive_type'  => 'nullable|in:none,fixed,percentage',
            'incentive_value' => 'nullable|numeric|min:0',
        ];
    }
}
