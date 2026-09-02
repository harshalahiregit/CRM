<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseKickoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_vendor_id'     => ['required', 'integer', Rule::exists('purchase_vendors', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'purchase_onboarding_id' => 'nullable|integer',
            'title'                  => 'nullable|string|max:200',
            'meeting_type'           => ['nullable', 'string', Rule::in(\App\Support\Purchase\PurchaseMeetingTypeCatalog::keys())],
            'reference'              => 'nullable|string|max:120',
            'agenda'                 => 'nullable|string',
            'priority'               => 'nullable|string|max:16',
            'confidentiality'        => 'nullable|string|max:16',
            'chairperson'            => 'nullable|string|max:160',
            'coordinator'            => 'nullable|string|max:160',
            'organizer'              => 'nullable|string|max:160',
            'department'             => 'nullable|string|max:120',
            'client_name'            => 'nullable|string|max:200',
            // Start + end mandatory and non-past; duration derived server-side.
            'scheduled_at'           => ['required', 'date', function ($attr, $value, $fail) {
                if (\Illuminate\Support\Carbon::parse($value)->lt(now()->subMinutes(2))) {
                    $fail('The meeting start time cannot be in the past.');
                }
            }],
            'end_at'                 => 'required|date|after:scheduled_at',
            'mode'                   => 'nullable|in:online,onsite,hybrid',
            'location'               => 'nullable|string|max:500',
            'meeting_platform'       => 'nullable|string|max:100',
            'meeting_link'           => 'nullable|string|max:1024',
            'meeting_id'             => 'nullable|string|max:150',
            'meeting_passcode'       => 'nullable|string|max:150',
            'meeting_host_link'      => 'nullable|string|max:1024',
            'participants'                       => 'nullable|array',
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
