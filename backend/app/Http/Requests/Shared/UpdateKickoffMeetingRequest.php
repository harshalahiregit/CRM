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
            'subject_ids'   => 'nullable|array',
            'subject_ids.*' => 'integer',
            'title'        => 'sometimes|required|string|max:200',
            'meeting_type' => 'nullable|string|in:'.implode(',', array_keys(config('meetings.types', []))),
            'reference'    => 'nullable|string|max:80',
            'agenda'       => 'nullable|string|max:5000',

            'scheduled_at'     => 'nullable|date',
            'end_at'           => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'mode'             => 'nullable|string|in:online,onsite,hybrid',
            'location'         => 'nullable|string|max:255',
            'planned_date'     => 'nullable|date',
            'city'             => 'nullable|string|max:120',
            'venue'            => 'nullable|string|max:180',
            'address'          => 'nullable|string|max:255',

            // Meeting.docx §2 detail fields.
            'priority'         => 'nullable|string|in:'.implode(',', config('meetings.meeting_priorities', ['Low', 'Medium', 'High', 'Urgent'])),
            'confidentiality'  => 'nullable|string|in:'.implode(',', config('meetings.confidentiality', ['Public', 'Internal', 'Confidential', 'Restricted'])),
            'chairperson'      => 'nullable|string|max:160',
            'coordinator'      => 'nullable|string|max:160',
            'department'       => 'nullable|string|max:120',
            'client_name'      => 'nullable|string|max:200',
            'work_package'     => 'nullable|string|max:200',

            // Itemised minutes. `id` MUST be listed — validated() drops nested keys
            // without a rule, and the service upserts by id (preserving action
            // tracking); omitting it would recreate every row on each edit.
            'mom_items'                             => 'nullable|array',
            'mom_items.*.id'                        => 'nullable|integer',
            'mom_items.*.carried_from_id'           => 'nullable|integer',
            'mom_items.*.priority'                  => 'nullable|string',
            'mom_items.*.responsible_org'           => 'nullable|string|max:160',
            'mom_items.*.description'               => 'required|string|max:5000',
            'mom_items.*.responsible_attendee_id'   => 'nullable|integer',
            'mom_items.*.remark'                    => 'nullable|string|max:2000',
            'mom_items.*.notes'                     => 'nullable|string|max:5000',
            'mom_items.*.target_date'               => 'nullable|date',

            // Aliases the existing Kickoff form already posts — see the store request.
            'location_detail'         => 'nullable|string|max:255',
            'mom_items.*.responsible' => 'nullable|string|max:500',
            'mom_items.*.remarks'     => 'nullable|string|max:2000',

            // Structured agenda (Agenda Builder) — see the store request.
            'agenda_items'                     => 'nullable|array',
            'agenda_items.*.item'              => 'required|string|max:255',
            'agenda_items.*.description'       => 'nullable|string|max:2000',
            'agenda_items.*.owner_attendee_id' => 'nullable|integer',
            'agenda_items.*.owner_names'       => 'nullable|string|max:500',
            'agenda_items.*.owner'             => 'nullable|string|max:500',
            'agenda_items.*.duration_minutes'  => 'nullable|integer|min:1|max:1440',
            'agenda_items.*.priority'          => 'nullable|string|in:'.implode(',', config('meetings.priorities', ['Low', 'Medium', 'High'])),

            // Decision register (Meeting.docx §9).
            'decisions'                          => 'nullable|array',
            'decisions.*.id'                     => 'nullable|integer',
            'decisions.*.decision'               => 'required|string|max:5000',
            'decisions.*.decided_by_attendee_id' => 'nullable|integer',
            'decisions.*.decided_by_names'       => 'nullable|string|max:300',
            'decisions.*.decided_by'             => 'nullable|string|max:300',
            'decisions.*.impact'                 => 'nullable|string|max:2000',
            'decisions.*.effective_date'         => 'nullable|date',
            'decisions.*.status'                 => 'nullable|string',

            // Issues raised (Meeting.docx §10). status/conversion are engine-owned.
            'issues'                       => 'nullable|array',
            'issues.*.id'                  => 'nullable|integer',
            'issues.*.carried_from_id'     => 'nullable|integer',
            'issues.*.title'               => 'required|string|max:255',
            'issues.*.description'         => 'nullable|string|max:5000',
            'issues.*.category'            => 'nullable|string|max:60',
            'issues.*.severity'            => 'nullable|string',
            'issues.*.owner_attendee_id'   => 'nullable|integer',
            'issues.*.owner_names'         => 'nullable|string|max:300',
            'issues.*.owner'               => 'nullable|string|max:300',
            'issues.*.due_date'            => 'nullable|date',

            'attendees'                     => 'sometimes|array',
            'attendees.*.vendor_contact_id' => 'nullable|integer',
            'attendees.*.name'              => 'required_without:attendees.*.vendor_contact_id|nullable|string|max:120',
            'attendees.*.email'             => 'nullable|email|max:180',
            'attendees.*.phone'             => 'nullable|string|max:40',
            'attendees.*.organisation'      => 'nullable|string|max:120',
            'attendees.*.role'              => 'nullable|string|max:60',
            'attendees.*.designation'       => 'nullable|string|max:120',
            'attendees.*.side'              => 'nullable|string|in:internal,external',
            'attendees.*.attended'          => 'nullable|boolean',
        ];
    }
}
