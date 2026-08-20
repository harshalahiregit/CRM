<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One structured agenda item on a meeting (Meeting.docx §3 — the Agenda Builder).
 *
 * Sits beside the free-text kickoff_meetings.agenda column exactly as
 * KickoffMomItem sits beside the free-text `minutes`: the prose field stays for
 * back-compat, this is the itemised form (topic · owner · duration · priority).
 * The owner is a kickoff_attendees id — the same identity list the MOM items use,
 * so a single person model serves the whole meeting.
 */
class MeetingAgendaItem extends Model
{
    use BelongsToTenant;

    protected $table = 'meeting_agenda_items';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id',
        'item', 'description',
        'owner_attendee_id', 'owner_names',
        'duration_minutes', 'priority', 'sort_order',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'sort_order'       => 'integer',
    ];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    public function owner()
    {
        return $this->belongsTo(KickoffAttendee::class, 'owner_attendee_id');
    }
}
