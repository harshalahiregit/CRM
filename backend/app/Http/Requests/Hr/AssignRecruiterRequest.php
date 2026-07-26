<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class AssignRecruiterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // null clears the assignment; tenant membership is verified in the service.
            'recruiter_id' => 'nullable|integer|exists:users,id',
        ];
    }
}
