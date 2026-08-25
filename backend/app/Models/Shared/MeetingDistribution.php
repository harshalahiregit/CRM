<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of one meeting send (Meeting.docx §13 — Sent / Viewed /
 * Acknowledged, per person rather than per meeting).
 *
 * Written by KickoffMeetingService when an invitation or the minutes go out.
 * `viewed_at` is stamped when that recipient opens the public MOM link, so the
 * coordinator can see who has actually read the minutes instead of only whether
 * the send happened.
 */
class MeetingDistribution extends Model
{
    use BelongsToTenant;

    protected $table = 'meeting_distributions';

    /** What was sent. */
    public const KIND_INVITE = 'invite';

    public const KIND_MOM = 'mom';

    /** §13's recipient groups. */
    public const PARTY_INTERNAL = 'internal';

    public const PARTY_VENDOR = 'vendor';

    public const PARTY_CLIENT = 'client';

    public const PARTY_MANAGEMENT = 'management';

    public const PARTY_OTHER = 'other';

    public const PARTIES = [
        self::PARTY_INTERNAL, self::PARTY_VENDOR, self::PARTY_CLIENT,
        self::PARTY_MANAGEMENT, self::PARTY_OTHER,
    ];

    /** A recipient with no address is 'skipped', never a silent success. */
    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'kickoff_meeting_id', 'kind', 'kickoff_attendee_id', 'user_id',
        'party', 'name', 'email', 'channel', 'token', 'status', 'error',
        'sent_at', 'viewed_at', 'acknowledged_at',
    ];

    /** The read token is a bearer credential — never list it in an API payload. */
    protected $hidden = ['token'];

    protected $casts = [
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    protected $appends = ['state_label'];

    public function meeting()
    {
        return $this->belongsTo(KickoffMeeting::class, 'kickoff_meeting_id');
    }

    public function attendee()
    {
        return $this->belongsTo(KickoffAttendee::class, 'kickoff_attendee_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The furthest state this recipient reached, for the tracker column. */
    public function getStateLabelAttribute(): string
    {
        if ($this->acknowledged_at) {
            return 'Acknowledged';
        }
        if ($this->viewed_at) {
            return 'Viewed';
        }
        if ($this->status === self::SKIPPED) {
            return 'No address';
        }
        if ($this->status === self::FAILED) {
            return 'Failed';
        }

        return 'Sent';
    }
}
