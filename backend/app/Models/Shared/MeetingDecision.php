<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A decision recorded at a meeting (Meeting.docx §9 — the Decision Register).
 *
 * Hangs off the meeting and inherits its vendor/subject, so decisions become a
 * searchable register rather than sentences buried in the minutes.
 */
class MeetingDecision extends Model
{
    use BelongsToTenant;

    protected $table = 'meeting_decisions';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id', 'decision_ref',
        'decision', 'decided_by_attendee_id', 'decided_by_names',
        'impact', 'effective_date', 'status', 'sort_order',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'sort_order'     => 'integer',
    ];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(KickoffAttendee::class, 'decided_by_attendee_id');
    }
}
