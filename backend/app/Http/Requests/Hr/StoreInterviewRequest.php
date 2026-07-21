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
            'interviewers.*.type'    => 'nullable|string',   // Internal Staff | Client | Vendor | Third Party Vendor
            'interviewers.*.user_id' => 'nullable|integer',  // existing CRM user id
            // No past dates (SPK-1). after_or_equal:today rejects yesterday and
            // earlier with 422 even when the UI is bypassed; today is allowed.
            'scheduled_at'         => 'required|date|after_or_equal:today',
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
