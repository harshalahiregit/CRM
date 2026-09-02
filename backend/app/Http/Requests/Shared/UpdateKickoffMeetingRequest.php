<?php

namespace App\Http\Requests\Shared;

use App\Support\Shared\MeetingTypeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'subject_ids' => 'nullable|array',
            'subject_ids.*' => 'integer',
            'title' => 'sometimes|required|string|max:200',
            'meeting_type' => 'nullable|string|in:'.implode(',', app(MeetingTypeCatalog::class)->keys($this->user()->tenant_id)),
            'reference' => 'nullable|string|max:80',
            'agenda' => 'nullable|string|max:5000',

            // Duration is derived server-side from start+end; not accepted here.
            // "not in the past" is enforced only when the start is actually being
            // MOVED (see withValidator) so re-editing an existing past meeting to
            // fix its notes is never blocked.
            'scheduled_at' => 'sometimes|required|date',
            'end_at' => 'sometimes|required|date|after:scheduled_at',
            'mode' => 'nullable|string|in:online,onsite,hybrid',
            'location' => 'nullable|string|max:255',
            'planned_date' => 'nullable|date',
            'city' => 'nullable|string|max:120',
            'venue' => 'nullable|string|max:180',
            'address' => 'nullable|string|max:255',

            // Meeting.docx §2 detail fields.
            'priority' => 'nullable|string|in:'.implode(',', config('meetings.meeting_priorities', ['Low', 'Medium', 'High', 'Urgent'])),
            'confidentiality' => 'nullable|string|in:'.implode(',', config('meetings.confidentiality', ['Public', 'Internal', 'Confidential', 'Restricted'])),
            'chairperson' => 'nullable|string|max:160',
            'organizer' => 'nullable|string|max:160',
            'coordinator' => 'nullable|string|max:160',
            'department' => 'nullable|string|max:120',
            'client_name' => 'nullable|string|max:200',
            // Soft link into the Customer module (Meeting.docx §2/§16). Scoped to
            // the tenant; the picker is served from CustomerServiceContract, so the
            // meetings engine still never queries the customers table.
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'work_package' => 'nullable|string|max:200',
            // Soft link into Shivam's Projects module (Meeting.docx §16).
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $this->user()->tenant_id)],

            // Itemised minutes. `id` MUST be listed — validated() drops nested keys
            // without a rule, and the service upserts by id (preserving action
            // tracking); omitting it would recreate every row on each edit.
            'mom_items' => 'nullable|array',
            'mom_items.*.id' => 'nullable|integer',
            'mom_items.*.carried_from_id' => 'nullable|integer',
            'mom_items.*.priority' => 'nullable|string',
            'mom_items.*.responsible_org' => 'nullable|string|max:160',
            'mom_items.*.description' => 'required|string|max:5000',
            'mom_items.*.responsible_attendee_id' => 'nullable|integer',
            'mom_items.*.remark' => 'nullable|string|max:2000',
            'mom_items.*.notes' => 'nullable|string|max:5000',
            'mom_items.*.target_date' => 'nullable|date',
            // Per-agenda-item structure + action dependency (Meeting.docx §7/§8).
            'mom_items.*.client_key' => 'nullable|string|max:64',
            'mom_items.*.agenda_client_key' => 'nullable|string|max:64',
            'mom_items.*.agenda_item_id' => 'nullable|integer',
            'mom_items.*.depends_on_client_key' => 'nullable|string|max:64',

            // Aliases the existing Kickoff form already posts — see the store request.
            'location_detail' => 'nullable|string|max:255',
            'mom_items.*.responsible' => 'nullable|string|max:500',
            'mom_items.*.remarks' => 'nullable|string|max:2000',

            // Structured agenda (Agenda Builder) — see the store request.
            'agenda_items' => 'nullable|array',
            'agenda_items.*.item' => 'required|string|max:255',
            'agenda_items.*.description' => 'nullable|string|max:2000',
            // Meeting.docx §7 — Agenda -> Discussion -> Decision -> Action, captured
            // per agenda item instead of one meeting-level minutes blob.
            'agenda_items.*.discussion' => 'nullable|string|max:5000',
            'agenda_items.*.decision' => 'nullable|string|max:5000',
            // §9 — supporting documents + previous-discussion reference.
            'agenda_items.*.supporting_documents' => 'nullable|array',
            'agenda_items.*.supporting_documents.*' => 'string|max:255',
            'agenda_items.*.previous_discussion_ref' => 'nullable|string|max:200',
            'agenda_items.*.owner_attendee_id' => 'nullable|integer',
            'agenda_items.*.owner_names' => 'nullable|string|max:500',
            'agenda_items.*.owner' => 'nullable|string|max:500',
            'agenda_items.*.duration_minutes' => 'nullable|integer|min:1|max:1440',
            'agenda_items.*.priority' => 'nullable|string|in:'.implode(',', config('meetings.priorities', ['Low', 'Medium', 'High'])),
            'agenda_items.*.id' => 'nullable|integer',
            'agenda_items.*.client_key' => 'nullable|string|max:64',

            // Decision register (Meeting.docx §9).
            'decisions' => 'nullable|array',
            'decisions.*.id' => 'nullable|integer',
            'decisions.*.decision' => 'required|string|max:5000',
            'decisions.*.decided_by_attendee_id' => 'nullable|integer',
            'decisions.*.decided_by_names' => 'nullable|string|max:300',
            'decisions.*.decided_by' => 'nullable|string|max:300',
            'decisions.*.impact' => 'nullable|string|max:2000',
            'decisions.*.effective_date' => 'nullable|date',
            'decisions.*.status' => 'nullable|string',
            'decisions.*.agenda_client_key' => 'nullable|string|max:64',
            'decisions.*.agenda_item_id' => 'nullable|integer',

            // Issues raised (Meeting.docx §10). status/conversion are engine-owned.
            'issues' => 'nullable|array',
            'issues.*.id' => 'nullable|integer',
            'issues.*.carried_from_id' => 'nullable|integer',
            'issues.*.title' => 'required|string|max:255',
            'issues.*.description' => 'nullable|string|max:5000',
            'issues.*.category' => 'nullable|string|max:60',
            'issues.*.severity' => 'nullable|string',
            'issues.*.owner_attendee_id' => 'nullable|integer',
            'issues.*.owner_names' => 'nullable|string|max:300',
            'issues.*.owner' => 'nullable|string|max:300',
            'issues.*.due_date' => 'nullable|date',

            'attendees' => 'sometimes|array',
            'attendees.*.vendor_contact_id' => 'nullable|integer',
            'attendees.*.name' => 'required_without:attendees.*.vendor_contact_id|nullable|string|max:120',
            'attendees.*.email' => 'nullable|email|max:180',
            // Meeting.docx §5 — "participants should be linked to Sangoe
            // identities wherever possible". Verified against the tenant in the
            // service before it is stored.
            'attendees.*.user_id' => 'nullable|integer',
            'attendees.*.phone' => 'nullable|string|max:40',
            'attendees.*.organisation' => 'nullable|string|max:120',
            'attendees.*.role' => 'nullable|string|max:60',
            'attendees.*.designation' => 'nullable|string|max:120',
            'attendees.*.side' => 'nullable|string|in:internal,external',
            'attendees.*.attended' => 'nullable|boolean',
        ];
    }

    /**
     * Block a past start ONLY when the admin is genuinely moving the time to a
     * past moment. If scheduled_at is unchanged from what is stored (a common
     * case when editing minutes on an older meeting), we leave it alone — this
     * is what previously made re-editing a past meeting impossible.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('scheduled_at')) {
                return;
            }
            $new = \Illuminate\Support\Carbon::parse($this->input('scheduled_at'));
            $meeting = $this->route('kickoffMeeting');
            $stored = $meeting && $meeting->scheduled_at
                ? \Illuminate\Support\Carbon::parse($meeting->scheduled_at)
                : null;

            $isMoved = ! $stored || ! $new->equalTo($stored);
            if ($isMoved && $new->lt(now()->subMinutes(2))) {
                $validator->errors()->add('scheduled_at', 'The meeting start time cannot be in the past.');
            }
        });
    }
}
