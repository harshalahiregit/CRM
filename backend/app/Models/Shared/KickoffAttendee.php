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
    ];

    protected $casts = [
        'attended' => 'boolean',
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
