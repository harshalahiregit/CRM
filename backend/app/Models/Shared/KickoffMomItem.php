<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * One action point from a kickoff meeting's minutes.
 *
 * The free-text `minutes` column on the meeting is unchanged and still renders
 * for anything minuted as prose; these are the itemised form, where a target
 * date is a date and an owner is a row rather than a sentence.
 *
 * Not Auditable: an item is a child of the meeting and is rewritten wholesale
 * on save, so per-row audit entries would record churn rather than intent. The
 * meeting's own audit trail records that the minutes changed.
 */
class KickoffMomItem extends Model
{
    protected $table = 'kickoff_mom_items';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id',
        'description', 'responsible_attendee_id', 'responsible_names',
        'remark', 'notes', 'target_date', 'sort_order',
    ];

    protected $casts = [
        'target_date' => 'date',
        'sort_order'  => 'integer',
    ];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    /** The attendee accountable for this item, when one has been assigned. */
    public function responsible()
    {
        return $this->belongsTo(KickoffAttendee::class, 'responsible_attendee_id');
    }
}
