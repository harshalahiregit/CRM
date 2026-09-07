<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One attendee on a Purchase kickoff meeting. Links back to the Purchase-vendor
 * contact master (PurchaseContact) when the attendee is a known contact; a free
 * name/email is allowed for external parties. Purchase-owned.
 */
class PurchaseKickoffParticipant extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_kickoff_participants';

    /** Attendance states (Meeting.docx §6). Present/Online/Late/Offline count as attended. */
    public const ATTENDANCE = ['Present', 'Absent', 'Late', 'Excused', 'Online', 'Offline'];
    public const ATTENDING = ['Present', 'Late', 'Online', 'Offline'];

    protected $fillable = [
        'tenant_id', 'purchase_kickoff_meeting_id', 'purchase_contact_id', 'user_id',
        'name', 'email', 'phone', 'organisation', 'designation', 'role', 'side',
        'attended', 'attendance_status',
        // Written by the live room, not typed by anyone: when this person
        // arrived, when they were last seen, how long they were in the call,
        // and the call's own id for them. See MeetingPresence.
        'joined_at', 'left_at', 'seconds_in_call', 'participant_key', 'is_guest',
        // Whether this was observed in the call, recorded when they pressed
        // Join, or ticked by hand. See MeetingJoinRecorder.
        'attendance_source',
    ];

    protected $casts = [
        'attended' => 'boolean',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'seconds_in_call' => 'integer',
        'is_guest' => 'boolean',
    ];

    public function meeting()
    {
        return $this->belongsTo(PurchaseKickoffMeeting::class, 'purchase_kickoff_meeting_id');
    }

    public function contact()
    {
        return $this->belongsTo(PurchaseContact::class, 'purchase_contact_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
