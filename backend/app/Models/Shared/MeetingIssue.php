<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Support\Shared\MeetingIssueStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * An issue raised at a meeting (Meeting.docx §10 — Issues Raised).
 *
 * Trackable through a lifecycle and escalatable: an issue can be converted into
 * an Incident (and, later, NCR/CAPA/Task) — the converted_* fields record what it
 * became. Content is owned by the meeting form; lifecycle + conversion are owned
 * by the issue engine and survive a form edit (upsert by id).
 */
class MeetingIssue extends Model
{
    use BelongsToTenant;

    protected $table = 'meeting_issues';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id', 'issue_ref',
        'title', 'description', 'category', 'severity',
        'owner_attendee_id', 'owner_names', 'due_date', 'status',
        'converted_to', 'converted_ref', 'converted_id', 'sort_order',
    ];

    protected $casts = [
        'due_date'   => 'date',
        'sort_order' => 'integer',
    ];

    protected $appends = ['status_label', 'is_open', 'is_overdue', 'is_converted'];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    public function owner()
    {
        return $this->belongsTo(KickoffAttendee::class, 'owner_attendee_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return MeetingIssueStatus::label($this->status);
    }

    public function getIsOpenAttribute(): bool
    {
        return MeetingIssueStatus::isOpen($this->status);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->is_open
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function getIsConvertedAttribute(): bool
    {
        return $this->converted_to !== null;
    }
}
