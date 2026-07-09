<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both reject-l1 and reject-l2 — remarks are required on rejection.
 */
class RejectManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => 'required|string|max:500',
        ];
    }
}
