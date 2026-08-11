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
            // Additional vendors. subject_id above stays the PRIMARY and keeps
            // living on kickoffable_*; these are the rest of the set.
            'subject_ids'   => 'nullable|array',
            'subject_ids.*' => 'integer',

            'scheduled_at'     => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'mode'             => 'nullable|string|in:online,onsite',
            'location'         => 'nullable|string|max:255',
            'planned_date'     => 'nullable|date',
            'city'             => 'nullable|string|max:120',
            'venue'            => 'nullable|string|max:180',
            'address'          => 'nullable|string|max:255',

            // Itemised minutes. responsible_attendee_id is re-checked in the
            // service against THIS meeting's attendees; the rule only shapes it.
            'mom_items'                             => 'nullable|array',
            'mom_items.*.description'               => 'required|string|max:5000',
            'mom_items.*.responsible_attendee_id'   => 'nullable|integer',
            'mom_items.*.remark'                    => 'nullable|string|max:2000',
            'mom_items.*.notes'                     => 'nullable|string|max:5000',
            'mom_items.*.target_date'               => 'nullable|date',

            // Field names the existing Kickoff form already posts. Accepted as
            // aliases so that form keeps working unchanged: `location_detail`
            // is the venue, `responsible` is a free-typed list of names, and
            // `remarks` is the singular `remark`.
            'location_detail'         => 'nullable|string|max:255',
            'mom_items.*.responsible' => 'nullable|string|max:500',
            'mom_items.*.remarks'     => 'nullable|string|max:2000',

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
