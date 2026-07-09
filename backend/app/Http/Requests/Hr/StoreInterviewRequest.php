<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_id'     => 'required|exists:hr_candidates,id',
            'round_name'       => 'required|string',
            'interviewer_name' => 'nullable|string',
            'scheduled_at'     => 'required|date',
            'meet_link'        => 'nullable|url',
        ];
    }
}
