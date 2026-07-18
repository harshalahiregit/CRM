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

    protected $fillable = [
        'tenant_id','kickoff_meeting_id','vendor_contact_id','user_id',
        'name','email','organisation','role','attended',
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
