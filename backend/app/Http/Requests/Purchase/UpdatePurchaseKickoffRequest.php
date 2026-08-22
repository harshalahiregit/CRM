<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'meeting_type'      => ['sometimes', 'nullable', 'string', Rule::in(\App\Support\Purchase\PurchaseMeetingTypeCatalog::keys())],
            'reference'         => 'sometimes|nullable|string|max:120',
            'agenda'            => 'sometimes|nullable|string',
            'priority'          => 'sometimes|nullable|string|max:16',
            'confidentiality'   => 'sometimes|nullable|string|max:16',
            'chairperson'       => 'sometimes|nullable|string|max:160',
            'coordinator'       => 'sometimes|nullable|string|max:160',
            'organizer'         => 'sometimes|nullable|string|max:160',
            'department'        => 'sometimes|nullable|string|max:120',
            'client_name'       => 'sometimes|nullable|string|max:200',
            'scheduled_at'      => 'sometimes|nullable|date',
            'end_at'            => 'sometimes|nullable|date',
            'duration_minutes'  => 'sometimes|nullable|integer|min:0|max:1440',
            'mode'              => 'sometimes|nullable|in:online,onsite,hybrid',
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
            'participants.*.designation'         => 'nullable|string|max:120',
            'participants.*.phone'               => 'nullable|string|max:40',
            'participants.*.side'                => 'nullable|in:internal,external',
            'participants.*.role'                => 'nullable|string|max:100',
            'participants.*.attended'            => 'nullable|boolean',
        ];
    }
}
