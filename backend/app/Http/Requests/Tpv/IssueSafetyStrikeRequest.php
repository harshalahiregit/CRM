<?php

namespace App\Http\Requests\Tpv;

use App\Support\Tpv\StrikeSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueSafetyStrikeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'severity'    => ['required', Rule::in(StrikeSeverity::ALL)],
            'reason'      => 'required|string|max:255',
            'notes'       => 'nullable|string',
            'location'    => 'nullable|string|max:120',
            'occurred_at' => 'nullable|date|before_or_equal:now',
        ];
    }
}
