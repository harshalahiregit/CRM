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
            'reference'              => 'nullable|string|max:120',
            'agenda'                 => 'nullable|string',
            'scheduled_at'           => 'nullable|date',
            'duration_minutes'       => 'nullable|integer|min:0|max:1440',
            'mode'                   => 'nullable|in:online,onsite',
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
            'participants.*.role'                => 'nullable|string|max:100',
            'participants.*.attended'            => 'nullable|boolean',
        ];
    }
}
