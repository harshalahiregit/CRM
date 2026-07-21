<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing an open meeting's details. Status is NOT changed here — that goes
 * through the transition endpoint, which enforces the lifecycle map.
 */
class UpdateKickoffMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'     => 'sometimes|required|string|max:200',
            'reference' => 'nullable|string|max:80',
            'agenda'    => 'nullable|string|max:5000',

            'scheduled_at'     => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'mode'             => 'nullable|string|in:online,onsite',
            'location'         => 'nullable|string|max:255',

            'attendees'                     => 'sometimes|array',
            'attendees.*.vendor_contact_id' => 'nullable|integer',
            'attendees.*.name'              => 'required_without:attendees.*.vendor_contact_id|nullable|string|max:120',
            'attendees.*.email'             => 'nullable|email|max:180',
            'attendees.*.organisation'      => 'nullable|string|max:120',
            'attendees.*.role'              => 'nullable|string|max:60',
            'attendees.*.attended'          => 'nullable|boolean',
        ];
    }
}
