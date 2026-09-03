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
            // Duration is derived server-side; non-past enforced only when the
            // start is actually MOVED (see withValidator).
            'scheduled_at'      => 'sometimes|required|date',
            'end_at'            => 'sometimes|required|date|after:scheduled_at',
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

    /** Block a past start ONLY when it is genuinely being moved to the past. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('scheduled_at')) {
                return;
            }
            $new = \Illuminate\Support\Carbon::parse($this->input('scheduled_at'));
            $meeting = $this->route('kickoff');
            $stored = $meeting && $meeting->scheduled_at
                ? \Illuminate\Support\Carbon::parse($meeting->scheduled_at)
                : null;
            if ((! $stored || ! $new->equalTo($stored)) && $new->lt(now()->subMinutes(2))) {
                $validator->errors()->add('scheduled_at', 'The meeting start time cannot be in the past.');
            }
        });
    }
}
