<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class SendInterviewNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'    => 'required|in:email_candidate,email_interviewer,whatsapp,calendar',
            // Optional edited email content from the preview popup (feature 6).
            'subject' => 'nullable|string|max:255',
            'body'    => 'nullable|string',
        ];
    }
}
