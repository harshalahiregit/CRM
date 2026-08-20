<?php

namespace App\Models\Shared;

use App\Models\User;
use App\Support\Shared\MomActionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One action point from a meeting's minutes — a trackable action (Meeting.docx §8).
 *
 * The free-text `minutes` column on the meeting is unchanged and still renders
 * for anything minuted as prose; these are the itemised form, where a target
 * date is a date and an owner is a row rather than a sentence.
 *
 * Content fields (description/owner/target_date/…) are owned by the meeting form
 * and re-synced on save. The lifecycle fields (status/evidence/verification) are
 * owned by the Action Engine and are preserved across a form save — the service
 * upserts by id rather than rewriting wholesale, so tracking is never lost.
 */
class KickoffMomItem extends Model
{
    protected $table = 'kickoff_mom_items';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id', 'agenda_item_id', 'depends_on_id', 'carried_from_id', 'action_ref',
        'description', 'responsible_attendee_id', 'responsible_names', 'responsible_org',
        'remark', 'notes', 'target_date', 'sort_order',
        'status', 'priority', 'evidence_path', 'verification_note',
        'closed_at', 'verified_at', 'verified_by',
    ];

    protected $casts = [
        'target_date' => 'date',
        'sort_order' => 'integer',
        'closed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $appends = ['status_label', 'is_open', 'is_overdue'];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    /** The attendee accountable for this item, when one has been assigned. */
    public function responsible()
    {
        return $this->belongsTo(KickoffAttendee::class, 'responsible_attendee_id');
    }

    /** The agenda item this action came out of, when linked. */
    public function agendaItem()
    {
        return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id');
    }

    /** The action this one is blocked by, until that one is done (Meeting.docx §8). */
    public function dependsOn()
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return MomActionStatus::label($this->status);
    }

    /** Still live — counts toward "open actions". */
    public function getIsOpenAttribute(): bool
    {
        return MomActionStatus::isOpen($this->status);
    }

    /** Open AND its target date has passed — the chase list. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->is_open
            && $this->target_date !== null
            && $this->target_date->isPast();
    }
}
