<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reschedule / edit an existing interview round. All fields optional — only the
 * provided ones are applied by InterviewService::reschedule().
 */
class UpdateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'round_name'           => 'sometimes|string',
            'mode'                 => 'sometimes|in:online,offline',
            'interviewer_name'     => 'nullable|string',
            'interviewers'         => 'nullable|array',
            'interviewers.*.name'  => 'required_with:interviewers|string',
            'interviewers.*.email' => 'nullable|email',
            'interviewers.*.type'    => 'nullable|string',
            'interviewers.*.user_id' => 'nullable|integer',
            // Reschedule must also stay today-or-future (SPK-1).
            'scheduled_at'         => 'sometimes|date|after_or_equal:today',
            'meet_link'            => 'nullable|url',
            'venue'                => 'nullable|string|max:255',
            'reminder_minutes'     => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.after_or_equal' => 'Interview cannot be scheduled in the past.',
        ];
    }
}
