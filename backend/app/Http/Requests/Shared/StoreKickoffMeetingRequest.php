<?php

namespace App\Http\Requests\Shared;

use App\Support\Shared\KickoffSubject;
use Illuminate\Foundation\Http\FormRequest;

class StoreKickoffMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'     => 'nullable|string|max:200',
            'reference' => 'nullable|string|max:80',
            'agenda'    => 'nullable|string|max:5000',

            // Stable key from the allowlist — never a class name. Both or neither.
            'subject_type' => 'nullable|string|in:'.implode(',', array_keys(KickoffSubject::MAP)).'|required_with:subject_id',
            'subject_id'   => 'nullable|integer|required_with:subject_type',

            'scheduled_at'     => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'mode'             => 'nullable|string|in:online,onsite',
            'location'         => 'nullable|string|max:255',

            'attendees'                     => 'nullable|array',
            'attendees.*.vendor_contact_id' => 'nullable|integer',
            'attendees.*.name'              => 'required_without:attendees.*.vendor_contact_id|nullable|string|max:120',
            'attendees.*.email'             => 'nullable|email|max:180',
            'attendees.*.organisation'      => 'nullable|string|max:120',
            'attendees.*.role'              => 'nullable|string|max:60',
            'attendees.*.attended'          => 'nullable|boolean',
        ];
    }
}
