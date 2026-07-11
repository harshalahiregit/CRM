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
            'candidate_id'         => 'required|exists:hr_candidates,id',
            'round_name'           => 'required|string',
            'mode'                 => 'nullable|in:online,offline',
            'interviewer_name'     => 'nullable|string',
            'interviewers'         => 'nullable|array',
            'interviewers.*.name'  => 'required_with:interviewers|string',
            'interviewers.*.email' => 'nullable|email',
            'scheduled_at'         => 'required|date',
            'meet_link'            => 'nullable|url',
            'venue'                => 'nullable|string|max:255',
            'reminder_minutes'     => 'nullable|integer|min:0',
        ];
    }
}
