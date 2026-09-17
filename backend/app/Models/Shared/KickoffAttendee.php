<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\VendorContact;
use Illuminate\Database\Eloquent\Model;

/**
 * One attendee on a kickoff meeting.
 *
 * Links back to the vendor master (VendorContact) when the attendee is a known
 * contact, so the registry isn't a pile of retyped names that drift from the
 * source. A free name/email is still allowed for external parties who aren't in
 * the master at all.
 */
class KickoffAttendee extends Model
{
    use BelongsToTenant;

    protected $table = 'kickoff_attendees';

    /** Attendance states (Meeting.docx §6). NULL = not marked yet. */
    public const PRESENT = 'Present';
    public const LATE    = 'Late';
    public const ABSENT  = 'Absent';
    public const EXCUSED = 'Excused';
    public const ONLINE  = 'Online';
    public const OFFLINE = 'Offline';

    public const STATUSES = [self::PRESENT, self::LATE, self::ABSENT, self::EXCUSED, self::ONLINE, self::OFFLINE];

    /** States that count as having turned up — the boolean projection.
     *  Online = joined remotely, Offline = attended in person; both are present. */
    public const ATTENDING = [self::PRESENT, self::LATE, self::ONLINE, self::OFFLINE];

    protected $fillable = [
        'tenant_id','kickoff_meeting_id','vendor_contact_id','user_id',
        'name','email','phone','organisation','role','designation','side','attended',
        'attendance_status','remark',
        // Which column of the four-column attendance sheet this person sits in
        // — organiser | client | vendor | tpv — and where they were picked from.
        // See MeetingPartyDirectory; party_ref is an opaque string, never a key.
        'party','party_ref',
        // Written by the live room, not typed by anyone: when this person
        // arrived, when they were last seen, how long they were in the call,
        // and the call's own id for them. See MeetingPresence.
        'joined_at', 'left_at', 'seconds_in_call', 'participant_key', 'is_guest',
        // Whether this was observed in the call, recorded when they pressed
        // Join, or ticked by hand. See MeetingJoinRecorder.
        'attendance_source',
        // Where and on what they joined from — the evidence behind the tick.
        // Coordinates only exist if the person's browser offered them.
        'join_ip', 'join_user_agent', 'join_device',
        'join_latitude', 'join_longitude', 'join_location_label',
        // The ORGANISER's decision, kept beside the claim above rather than on
        // top of it — see MeetingAttendanceReview. NULL verdict means nobody has
        // reviewed this person yet, which is not the same as absent.
        'verdict', 'verdict_from', 'verdict_to', 'verdict_note', 'verdict_by', 'verdict_at',
    ];

    protected $casts = [
        'attended' => 'boolean',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'seconds_in_call' => 'integer',
        'is_guest' => 'boolean',
        'verdict_from' => 'datetime',
        'verdict_to' => 'datetime',
        'verdict_at' => 'datetime',
    ];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    public function vendorContact()
    {
        return $this->belongsTo(VendorContact::class, 'vendor_contact_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
