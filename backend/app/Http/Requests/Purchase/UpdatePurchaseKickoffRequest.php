<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseKickoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'             => 'sometimes|nullable|string|max:200',
            'reference'         => 'sometimes|nullable|string|max:120',
            'agenda'            => 'sometimes|nullable|string',
            'scheduled_at'      => 'sometimes|nullable|date',
            'duration_minutes'  => 'sometimes|nullable|integer|min:0|max:1440',
            'mode'              => 'sometimes|nullable|in:online,onsite',
            'location'          => 'sometimes|nullable|string|max:500',
            'meeting_platform'  => 'sometimes|nullable|string|max:100',
            'meeting_link'      => 'sometimes|nullable|string|max:1024',
            'meeting_id'        => 'sometimes|nullable|string|max:150',
            'meeting_passcode'  => 'sometimes|nullable|string|max:150',
            'meeting_host_link' => 'sometimes|nullable|string|max:1024',
            'participants'                       => 'sometimes|nullable|array',
            'participants.*.purchase_contact_id' => 'nullable|integer',
            'participants.*.name'                => 'nullable|string|max:150',
            'participants.*.email'               => 'nullable|email|max:150',
            'participants.*.organisation'        => 'nullable|string|max:150',
            'participants.*.role'                => 'nullable|string|max:100',
            'participants.*.attended'            => 'nullable|boolean',
        ];
    }
}
